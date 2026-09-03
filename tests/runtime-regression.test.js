const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('path');
const vm = require('node:vm');

function readSource(relativePath) {
  return fs.readFileSync(path.join(__dirname, '..', relativePath), 'utf8');
}

function extractFunction(source, name) {
  const start = source.indexOf(`function ${name}(`);
  assert.notEqual(start, -1, `missing function ${name}`);
  const braceStart = source.indexOf('{', start);
  assert.notEqual(braceStart, -1, `missing body for ${name}`);
  let depth = 0;
  for (let index = braceStart; index < source.length; index += 1) {
    const char = source[index];
    if (char === '{') depth += 1;
    if (char === '}') depth -= 1;
    if (depth === 0) {
      return source.slice(start, index + 1);
    }
  }
  throw new Error(`unterminated function ${name}`);
}

function loadFunction(source, name, extras) {
  return vm.runInNewContext(`(${extractFunction(source, name)})`, Object.assign({ URLSearchParams }, extras || {}));
}

test('Wallet panel mounts on official profile route instead of cloning a sidebar dock', () => {
  const source = readSource('theme/XboardCustom/assets/wallet-center.js');
  assert.match(source, /OFFICIAL_PATH = "\/profile"/);
  assert.match(source, /findOfficialWalletCard/);
  assert.doesNotMatch(source, /xc-wallet-dock--sidebar/);
  assert.doesNotMatch(source, /resolveDockDisplayState/);
});

test('Wallet hash canonicalizes legacy wallet query onto official profile', () => {
  const source = readSource('theme/XboardCustom/assets/wallet-center.js');
  const parseHash = loadFunction(source, 'parseHash', { OFFICIAL_PATH: '/profile' });
  const parsed = parseHash('#/profile?section=topup&topup_trade_no=abc');
  assert.equal(parsed.path, '/profile');
  assert.equal(parsed.isWallet, true);
  assert.equal(parsed.section, 'topup');
  assert.equal(parsed.tradeNo, 'abc');
  const trailing = parseHash('#/profile/?section=topup');
  assert.equal(trailing.path, '/profile');
  assert.equal(trailing.isWallet, true);
  assert.match(source, /parsed\.path === "\/404" && \(parsed\.tradeNo \|\| lastTopup\)/);
  assert.match(source, /var existed = !!\(dom\.panel && dom\.panel\.parentElement && dom\.panel\.innerHTML\)/);
  assert.match(source, /restoreDraft/);
});

test('Auth page state sync adds delayed corrections and clears auth locale layout off auth routes', () => {
  const source = readSource('theme/XboardCustom/assets/i18n-extra.js');
  const syncAuthPageState = extractFunction(source, 'syncAuthPageState');
  const scheduleAuthPageStateSync = extractFunction(source, 'scheduleAuthPageStateSync');

  assert.match(syncAuthPageState, /clearAuthLocaleLayout\(\)/);
  assert.match(scheduleAuthPageStateSync, /requestAnimationFrame/);
  assert.match(scheduleAuthPageStateSync, /setTimeout/);
  assert.match(source, /window\.addEventListener\('pageshow'/);
});

test('Topup default return URL uses official profile wallet page', () => {
  const source = readSource('plugins/WalletCenter/Services/TopupGatewayService.php');
  assert.match(source, /#\/profile\?section=topup&topup_trade_no=/);
  assert.doesNotMatch(source, /handleStripeNotify/);
  assert.match(source, /inspectNotification/);
  assert.doesNotMatch(source, /return \$referer;/);
  assert.match(source, /\$actualAmount === null \|\| \(int\) \$actualAmount !== \$expectedAmount/);
  assert.match(source, /currency mismatch/);
});

test('Stripe and BEpusdt expose inspectNotification for shared webhook verification', () => {
  const stripe = readSource('plugins/StripePayment/Plugin.php');
  const bepusdt = readSource('plugins/BepusdtPayment/Plugin.php');
  assert.match(stripe, /function inspectNotification/);
  assert.match(bepusdt, /function inspectNotification/);
  assert.doesNotMatch(stripe, /OrderHandleJob::dispatchSync/);
  assert.doesNotMatch(bepusdt, /OrderHandleJob::dispatchSync/);
  assert.match(stripe, /\$paymentIntent\['metadata'\]\['trade_no'\]/);
});

test('WalletCenter controllers extend official PluginController', () => {
  const source = readSource('plugins/WalletCenter/Controllers/BaseController.php');
  assert.match(source, /extends PluginController/);
  assert.match(source, /beforePluginAction/);
});

test('History APIs paginate instead of returning a short slice', () => {
  const topup = readSource('plugins/WalletCenter/Services/TopupService.php');
  const checkin = readSource('plugins/WalletCenter/Services/CheckinService.php');
  const renew = readSource('plugins/WalletCenter/Services/AutoRenewService.php');
  assert.match(topup, /function paginateHistoryForUser/);
  assert.match(checkin, /function paginateHistoryForUser/);
  assert.match(renew, /function paginateHistoryForUser/);
});

test('i18n extra locales keep {total} placeholder and naive locale aliases', () => {
  const source = readSource('theme/XboardCustom/assets/i18n-extra.js');
  assert.doesNotMatch(source, /Total \{used\}/);
  assert.match(source, /\{used\}.*\{total\}/);
  assert.match(source, /naiveLocaleAlias/);
  assert.match(source, /"ar-SA": "fa-IR"/);
});

test('Wallet UI uses Naive card/radio/pagination classes and payment icons', () => {
  const source = readSource('theme/XboardCustom/assets/wallet-center.js');
  assert.match(source, /n-card n-card--bordered/);
  assert.match(source, /n-radio-group/);
  assert.match(source, /paymentIcon/);
  assert.match(source, /exportCsv/);
  assert.match(source, /n-pagination/);
});

test('Wallet RTL uses logical inset properties', () => {
  const source = readSource('theme/XboardCustom/assets/wallet-center.css');
  assert.match(source, /inset-inline-end/);
  assert.match(source, /html\[dir="rtl"\]/);
});
