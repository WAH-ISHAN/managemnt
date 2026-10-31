'use strict';

// Executes the actual NIBM-origin fetch script, with fictional tokens and no network.
// This checks the bridge contract; it does not replace a real NIBM OTP sign-in.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

let checks = 0;
const check = (condition, message) => { assert.ok(condition, message); checks++; };
const source = fs.readFileSync(path.join(__dirname, '../launcher/NibmCalendar.cs'), 'utf8');
const extracted = source.match(/private const string FetchScript\s*=\s*"""\r?\n([\s\S]*?)\r?\n\s*""";/);
assert.ok(extracted, 'Native calendar fetch script is available for direct execution');
const parameters = {
  request: 'fixture-request', batchId: '1f8d4470-5a5a-4f90-98df-af06b2a73422',
  startAt: '2026-09-01T00:00:00Z', endAt: '2027-04-30T23:59:59Z'
};
const access = 'fictional-access-token-never-post-to-LifeOS';
const refresh = 'fictional-refresh-token-never-post-to-LifeOS';
const updatedAccess = 'fictional-refreshed-access-token';
const updatedRefresh = 'fictional-refreshed-refresh-token';
const lecture = () => ({
  lectureId: 'cc7f12ba-6eef-4bc0-9432-99f9875eca01', batchId: parameters.batchId,
  moduleName: 'Database systems', lectureType: 'LECTURE_PHYSICAL',
  startAt: '2026-10-30T03:30:00Z', endAt: '2026-10-30T05:30:00Z',
  status: 'APPROVED', facilityName: 'Hall A', internalLecturerName: 'Fictional lecturer'
});
const response = (value, status = 200) => new Response(JSON.stringify(value), {
  status, headers: { 'Content-Type': 'application/json' }
});
const lectures = (rows = [lecture()]) => response({ data: { lectures: rows } });

async function run({ tokens = { accessToken: access, refreshToken: refresh }, origin = 'https://aca.mynibm.com', replies = [], onFetch } = {}) {
  const calls = [], messages = [], writes = [], timers = new Map();
  const storage = new Map(Object.entries(tokens));
  const localStorage = {
    getItem: key => storage.get(key) ?? null,
    setItem: (key, value) => { writes.push([key, String(value)]); storage.set(key, String(value)); }
  };
  let timerId = 0;
  const execute = vm.runInNewContext('(' + extracted[1] + ')', {
    location: { origin }, localStorage, URLSearchParams, AbortController, TextEncoder,
    window: { chrome: { webview: { postMessage: value => messages.push(JSON.parse(JSON.stringify(value))) } } },
    setTimeout(callback, delay) { timers.set(++timerId, { callback, delay }); return timerId; },
    clearTimeout(id) { timers.delete(id); },
    fetch: async (url, options = {}) => {
      const index = calls.length;
      calls.push({ url: String(url), options });
      if (onFetch) return onFetch({ index, url: String(url), options, timers });
      assert.ok(index < replies.length, 'Fixture received an unexpected network request');
      return typeof replies[index] === 'function' ? replies[index]() : replies[index];
    }
  }, { filename: 'launcher/NibmCalendar.cs:FetchScript' });
  await execute(parameters);
  return { calls, messages, writes, storage, timers };
}

function failure(result, code, label) {
  check(result.messages.length === 1 && result.messages[0].ok === false, label + ': one failure result');
  if (code) check(result.messages[0].code === code, label + ': appropriate failure state');
  check(!Object.hasOwn(result.messages[0], 'lectures'), label + ': no successful empty calendar');
  check(result.timers.size === 0, label + ': timeout cleaned up');
}

(async () => {
  let result = await run({ tokens: {} });
  failure(result, 'signin', 'No university access token');
  check(result.calls.length === 0 && result.writes.length === 0, 'Missing credentials cause no fetch or token write');
  for (const token of [' ', 'contains whitespace', 'x'.repeat(16385)]) {
    result = await run({ tokens: { accessToken: token, refreshToken: refresh } });
    failure(result, 'signin', 'Invalid stored access credential');
    check(result.calls.length === 0 && result.writes.length === 0, 'Invalid credentials never enter a university request');
  }

  result = await run({ origin: 'https://untrusted.example', replies: [] });
  check(result.calls.length === 0 && result.messages.length === 0, 'Untrusted origins cannot fetch or post lecture results');

  const richLecture = { ...lecture(), accessToken: access, refreshToken: refresh, email: 'private@example.invalid',
    privateStudentList: [{ secret: 'private-student-data' }], unknownField: 'must-not-cross-bridge' };
  result = await run({ replies: [lectures([richLecture])] });
  check(result.messages.length === 1 && result.messages[0].ok && result.messages[0].lectures.length === 1, 'Valid response posts the actual lecture list');
  check(result.messages[0].request === parameters.request && result.messages[0].type === 'lifeos-nibm-result', 'Response correlates with the pending native request');
  check(result.messages[0].lectures[0].moduleName === 'Database systems' && result.messages[0].lectures[0].startAt === '2026-10-30T03:30:00Z', 'Whitelisted calendar fields retain their real values');
  check(!['accessToken', 'refreshToken', 'email', 'privateStudentList', 'unknownField'].some(key => Object.hasOwn(result.messages[0].lectures[0], key)), 'Credentials and unrelated university metadata are omitted');
  check(!JSON.stringify(result.messages).includes(access) && !JSON.stringify(result.messages).includes(refresh), 'Tokens never appear in the message sent to LifeOS');
  const requestUrl = new URL(result.calls[0].url);
  check(requestUrl.searchParams.get('batchId') === parameters.batchId && requestUrl.searchParams.get('startAt') === parameters.startAt && requestUrl.searchParams.get('endAt') === parameters.endAt, 'Fetch uses exactly the selected batch and complete snapshot window');
  check(result.calls[0].options.headers.Authorization === 'Bearer ' + access, 'Token is used only for the university API request');
  check(result.calls[0].options.credentials === 'omit' && result.calls[0].options.redirect === 'error', 'API requests omit browser cookies and refuse redirects');
  check(result.writes.length === 0 && result.timers.size === 0, 'Successful fetch does not rewrite credentials and clears timeout');

  result = await run({ replies: [response({}, 498), response({ data: { accessToken: updatedAccess, refreshToken: updatedRefresh } }), lectures()] });
  check(result.messages[0].ok === true && result.calls.length === 3, 'Expired token refreshes once and retries the lecture request');
  check(result.calls[1].url.endsWith('/identity-service/auth/refresh') && result.calls[1].options.method === 'POST', 'Refresh uses the university refresh endpoint');
  check(JSON.parse(result.calls[1].options.body).refreshToken === refresh, 'Refresh credential goes only to the university identity endpoint');
  check(result.calls[2].options.headers.Authorization === 'Bearer ' + updatedAccess, 'Retried lecture request uses the new access token');
  check(result.storage.get('accessToken') === updatedAccess && result.storage.get('refreshToken') === updatedRefresh, 'Refreshed tokens remain in the NIBM origin storage');
  check(![access, refresh, updatedAccess, updatedRefresh].some(value => JSON.stringify(result.messages).includes(value)), 'Neither old nor refreshed credentials cross the LifeOS bridge');

  result = await run({ replies: [response({}, 498), response({ data: { accessToken: updatedAccess, refreshToken: updatedRefresh } }), response({}, 498)] });
  failure(result, 'signin', 'Repeated expiry after refresh');
  check(result.calls.length === 3, 'Repeated expiry does not loop through additional refresh requests');
  result = await run({ tokens: { accessToken: access }, replies: [response({}, 498)] });
  failure(result, 'signin', 'Expired session without a refresh token');
  check(result.calls.length === 1, 'Missing refresh token makes no identity request');
  result = await run({ replies: [response({}, 498), response({}, 401)] });
  failure(result, 'signin', 'Rejected refresh token');
  check(result.calls.length === 2 && result.writes.length === 0, 'Rejected refresh cannot change stored credentials');

  for (const value of [{}, { data: { accessToken: null, refreshToken: updatedRefresh } }, { data: { accessToken: '', refreshToken: '' } },
    { data: { accessToken: ' ', refreshToken: updatedRefresh } }, { data: { accessToken: updatedAccess, refreshToken: 'x'.repeat(16385) } }]) {
    result = await run({ replies: [response({}, 498), response(value)] });
    failure(result, 'signin', 'Malformed or empty refresh credentials');
    check(result.calls.length === 2 && result.writes.length === 0, 'Invalid refreshed credentials are never saved or retried');
  }
  for (const status of [401, 403]) {
    result = await run({ replies: [response({}, status)] });
    failure(result, 'signin', 'Unauthorized lecture request ' + status);
  }
  result = await run({ replies: [response({}, 503)] });
  failure(result, 'network', 'University HTTP failure');

  for (const value of [{}, { data: {} }, { data: { lectures: {} } }, { data: { lectures: null } }]) {
    result = await run({ replies: [response(value)] });
    failure(result, 'response', 'Missing or non-list lecture payload');
  }
  const partialSnapshots = [
    { success: false, data: { lectures: [] } },
    { data: { lectures: [lecture()], complete: false } },
    { data: { lectures: [lecture()], pagination: { hasMore: true } } },
    { data: { lectures: [lecture()] }, pagination: { nextCursor: 'fictional-page-2' } },
    { data: { lectures: [lecture()], pagination: { totalCount: 7 } } },
    { total: 2, data: { lectures: [lecture()] } },
    { data: { lectures: [lecture()], nextPage: 2 } },
    { data: { lectures: [lecture()], pagination: { hasNextPage: true } } }
  ];
  for (const value of partialSnapshots) {
    result = await run({ replies: [response(value)] });
    failure(result, 'response', 'Incomplete or unsuccessful HTTP 200 snapshot');
  }
  result = await run({ replies: [response({ success: true, complete: true, data: {
    lectures: [lecture()], pagination: { hasMore: false, nextCursor: null, total: 1 }
  } })] });
  check(result.messages[0].ok === true && result.messages[0].lectures.length === 1, 'A complete final page with matching totals remains accepted');
  for (const row of [null, [], 'lecture', { ...lecture(), facilityName: { unexpected: true } }, { ...lecture(), moduleName: 'x'.repeat(4001) }]) {
    result = await run({ replies: [lectures([row])] });
    failure(result, null, 'Malformed lecture record');
  }
  result = await run({ replies: [lectures([])] });
  check(result.messages[0].ok === true && result.messages[0].lectures.length === 0, 'An actual successful empty lecture array remains a valid snapshot');
  result = await run({ replies: [lectures(Array.from({ length: 2000 }, lecture))] });
  check(result.messages[0].ok === true && result.messages[0].lectures.length === 2000, 'The supported 2000-lecture boundary is accepted');
  result = await run({ replies: [lectures(Array.from({ length: 2001 }, lecture))] });
  failure(result, 'response', 'Snapshot exceeds the PHP 2000-lecture limit');
  result = await run({ replies: [lectures(Array.from({ length: 2000 }, () => ({ ...lecture(), moduleName: 'x'.repeat(4000) })))] });
  failure(result, null, 'Oversized lecture metadata');
  const unicodeRows = Array.from({ length: 750 }, () => ({ ...lecture(), moduleName: '\u754c'.repeat(600) }));
  check(JSON.stringify(unicodeRows).length < 1500000 && new TextEncoder().encode(JSON.stringify(unicodeRows)).length > 1500000,
    'Multibyte fixture exceeds the byte limit while staying under the character limit');
  result = await run({ replies: [lectures(unicodeRows)] });
  failure(result, 'response', 'Oversized UTF-8 metadata');

  result = await run({ onFetch: ({ options, timers }) => {
    for (const timer of timers.values()) {
      check(timer.delay > 0 && timer.delay <= 45000, 'Network timeout has a bounded duration');
      timer.callback();
    }
    check(options.signal.aborted, 'Elapsed timeout aborts the actual API fetch');
    throw Error('Simulated abort; no real request was made');
  } });
  failure(result, 'network', 'Aborted university request');
  result = await run({ onFetch: () => { throw Error('Simulated offline network'); } });
  failure(result, 'network', 'Offline university request');
  console.log(`${checks} NIBM bridge fixture checks passed (fictional credentials; no real network).`);
})().catch(error => { console.error(error); process.exitCode = 1; });
