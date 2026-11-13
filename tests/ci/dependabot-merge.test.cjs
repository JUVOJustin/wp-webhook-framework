'use strict';

const { readFileSync } = require('node:fs');
const { test } = require('node:test');
const assert = require('node:assert/strict');

// Execute the actual trusted workflow script against a controlled GitHub API boundary.
const workflow = readFileSync('.github/workflows/dependabot-auto-merge.yml', 'utf8');
const script = workflow.split('          script: |\n')[1].replace(/^            /gm, '');
const AsyncFunction = Object.getPrototypeOf(async function () {}).constructor;
const evaluate = new AsyncFunction('github', 'context', 'core', script);

async function run(overrides = {}) {
  const merges = [];
  const errors = [];
  const pr = { number: 7, draft: false, user: { login: 'dependabot[bot]' }, head: { sha: 'checked-head', repo: { full_name: 'owner/repo' } } };
  const eligibility = { state: 'success', context: 'Dependabot patch eligibility', description: 'Verified patch update', creator: { login: 'github-actions[bot]' } };
  const state = {
    prs: [pr],
    statuses: { total_count: 1, statuses: [eligibility] },
    run: { status: 'completed', conclusion: 'success' },
    checks: [{ status: 'completed', conclusion: 'success' }],
    ...overrides,
  };
  const github = {
    rest: {
      pulls: {
        list: 'pulls',
        merge: async args => { merges.push(args); return { data: { merged: true } }; },
      },
      repos: { getCombinedStatusForRef: async () => ({ data: state.statuses }) },
      actions: { listWorkflowRuns: async () => ({ data: { workflow_runs: state.run ? [state.run] : [] } }) },
      checks: { listForRef: 'checks' },
    },
    paginate: async endpoint => endpoint === 'pulls' ? state.prs : state.checks,
  };
  await evaluate(github, { repo: { owner: 'owner', repo: 'repo' } }, { setFailed: message => errors.push(message) });
  return { merges, errors };
}

test('merges a verified patch only with the checked head SHA', async () => {
  assert.deepEqual((await run()).merges, [{ owner: 'owner', repo: 'repo', pull_number: 7, sha: 'checked-head', merge_method: 'squash' }]);
});

for (const outcome of ['failure', 'cancelled', 'skipped']) {
  test(`a ${outcome} CI workflow leaves the PR open`, async () => {
    assert.equal((await run({ run: { status: 'completed', conclusion: outcome } })).merges.length, 0);
  });
}

test('missing or unfinished CI and external checks leave the PR open', async () => {
  for (const state of [{ run: null }, { run: { status: 'in_progress' } }, { checks: [] }, { checks: [{ status: 'queued' }] }]) {
    assert.equal((await run(state)).merges.length, 0);
  }
});

test('manual, missing and incomplete eligibility evidence cannot merge', async () => {
  for (const statuses of [
    { total_count: 0, statuses: [] },
    { total_count: 101, statuses: [] },
    { total_count: 1, statuses: [{ state: 'success', context: 'Dependabot patch eligibility', description: 'Manual review required' }] },
    { total_count: 1, statuses: [{ state: 'failure', context: 'external' }] },
  ]) assert.equal((await run({ statuses })).merges.length, 0);
});

test('a successful rerun is evaluated independently of the previous failure', async () => {
  assert.equal((await run({ run: { status: 'completed', conclusion: 'failure' } })).merges.length, 0);
  assert.equal((await run()).merges.length, 1);
});

test('human-authored, draft and fork PRs stay manual', async () => {
  const base = { number: 7, draft: false, user: { login: 'dependabot[bot]' }, head: { sha: 'head', repo: { full_name: 'owner/repo' } } };
  for (const pr of [{ ...base, draft: true }, { ...base, user: { login: 'maintainer' } }, { ...base, head: { sha: 'head', repo: { full_name: 'fork/repo' } } }]) {
    assert.equal((await run({ prs: [pr] })).merges.length, 0);
  }
});


test('classification grants automatic merging only to patch metadata', async () => {
  const classifyScript = readFileSync('.github/workflows/dependabot-metadata.yml', 'utf8')
    .split('          script: |\n')[1].replace(/^            /gm, '');
  const classify = new AsyncFunction('github', 'context', 'process', classifyScript);
  for (const type of ['patch', 'minor', 'major', 'unknown']) {
    const statuses = [];
    await classify({ rest: { repos: { createCommitStatus: async status => statuses.push(status) } } },
      { repo: { owner: 'owner', repo: 'repo' }, payload: { pull_request: { head: { sha: 'classified-head' } } } },
      { env: { UPDATE_TYPE: `version-update:semver-${type}` } });
    assert.equal(statuses[0].sha, 'classified-head');
    assert.equal(statuses[0].description, type === 'patch' ? 'Verified patch update' : 'Manual review required');
  }
});
