const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
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

function loadFunction(source, name) {
  return vm.runInNewContext(`(${extractFunction(source, name)})`);
}

function normalize(value) {
  return JSON.parse(JSON.stringify(value));
}

test('Wallet dock display strategy keeps a visible mobile floating entry when sidebar is missing', () => {
  const source = readSource('theme/XboardCustom/assets/wallet-center.js');
  const resolveDockDisplayState = loadFunction(source, 'resolveDockDisplayState');

  assert.deepEqual(
    normalize(resolveDockDisplayState({
      allowDock: true,
      hasSidebar: false,
      isWalletRoute: false,
      isMobile: true,
      shellReady: false
    })),
    { mode: 'floating', visible: true, probe: true, floatingAllowed: true }
  );

  assert.deepEqual(
    normalize(resolveDockDisplayState({
      allowDock: true,
      hasSidebar: true,
      isWalletRoute: false,
      isMobile: true,
      shellReady: true
    })),
    { mode: 'sidebar', visible: true, probe: false, floatingAllowed: false }
  );

  assert.deepEqual(
    normalize(resolveDockDisplayState({
      allowDock: true,
      hasSidebar: false,
      isWalletRoute: true,
      isMobile: true,
      shellReady: false
    })),
    { mode: 'floating', visible: true, probe: false, floatingAllowed: true }
  );

  assert.deepEqual(
    normalize(resolveDockDisplayState({
      allowDock: true,
      hasSidebar: false,
      isWalletRoute: false,
      isMobile: false,
      shellReady: true
    })),
    { mode: 'floating', visible: false, probe: true, floatingAllowed: false }
  );
});

test('Wallet dock host creation is split from overlay and toast creation', () => {
  const source = readSource('theme/XboardCustom/assets/wallet-center.js');
  const ensureDockHost = extractFunction(source, 'ensureDockHost');
  const ensureWalletLayer = extractFunction(source, 'ensureWalletLayer');
  const ensureToastHost = extractFunction(source, 'ensureToastHost');

  assert.match(ensureDockHost, /dom\.dock = document\.createElement\("div"\)/);
  assert.doesNotMatch(ensureDockHost, /dom\.overlay\s*=/);
  assert.doesNotMatch(ensureDockHost, /dom\.toast\s*=/);

  assert.match(ensureWalletLayer, /dom\.overlay = document\.createElement\("div"\)/);
  assert.match(ensureWalletLayer, /dom\.shell = document\.createElement\("div"\)/);
  assert.doesNotMatch(ensureWalletLayer, /dom\.toast\s*=/);

  assert.match(ensureToastHost, /dom\.toast = document\.createElement\("div"\)/);
  assert.doesNotMatch(ensureToastHost, /dom\.overlay\s*=/);
});

test('Sidebar dock binding no longer intercepts pointer or click events in capture phase', () => {
  const source = readSource('theme/XboardCustom/assets/wallet-center.js');
  const bindDockInteractiveTarget = extractFunction(source, 'bindDockInteractiveTarget');

  assert.doesNotMatch(bindDockInteractiveTarget, /pointerdown/);
  assert.doesNotMatch(bindDockInteractiveTarget, /mousedown/);
  assert.doesNotMatch(bindDockInteractiveTarget, /addEventListener\("click"/);
  assert.match(bindDockInteractiveTarget, /addEventListener\("keydown"/);
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

test('Mobile CSS fallback hides floating dock unless runtime explicitly allows it', () => {
  const source = readSource('theme/XboardCustom/assets/wallet-center.css');
  assert.match(source, /body\[data-xc-wallet-floating-allowed="false"\] \.xc-wallet-dock--floating/);
});

test('Topup default return URL uses official dashboard wallet query instead of #/wallet', () => {
  const source = readSource('plugins/WalletCenter/Services/TopupGatewayService.php');
  assert.match(source, /#\/dashboard\?xc_wallet=1&section=topup&topup_trade_no=/);
  assert.doesNotMatch(source, /'#\/wallet\?topup_trade_no='/);
});

test('Stripe and BEpusdt notify no longer clone OrderHandleJob paid pipeline', () => {
  const stripe = readSource('plugins/StripePayment/Plugin.php');
  const bepusdt = readSource('plugins/BepusdtPayment/Plugin.php');
  assert.doesNotMatch(stripe, /OrderHandleJob::dispatchSync/);
  assert.doesNotMatch(bepusdt, /OrderHandleJob::dispatchSync/);
  assert.match(stripe, /if \(!is_array\(\$verify\)\)/);
  assert.match(bepusdt, /if \(!is_array\(\$verify\)\)/);
});

test('WalletCenter auto renew uses official OrderService paid path', () => {
  const source = readSource('plugins/WalletCenter/Services/AutoRenewService.php');
  assert.match(source, /OrderService::createFromRequest/);
  assert.match(source, /\$orderService->paid\(/);
  assert.doesNotMatch(source, /\$user->transfer_enable = \$plan->transfer_enable \* 1073741824/);
});

test('Topup notify is not gated by the frontend feature flag', () => {
  const source = readSource('plugins/WalletCenter/Controllers/TopupController.php');
  const notifyStart = source.indexOf('public function notify(');
  const notifyBlock = source.slice(notifyStart, source.indexOf('public function', notifyStart + 1) === -1 ? source.length : source.indexOf('function history', notifyStart));
  assert.match(source, /function notify\(Request \$request, string \$method, string \$uuid\)/);
  assert.doesNotMatch(notifyBlock, /requireFeature\(WalletCenterFeature::TOPUP\)/);
});

test('WalletCenter boot publishes guest_comm_config flags', () => {
  const source = readSource('plugins/WalletCenter/Plugin.php');
  assert.match(source, /guest_comm_config/);
  assert.match(source, /admin\.user\.transform/);
  assert.match(source, /wallet_hash/);
  assert.match(source, /payment\.notify\.before/);
  assert.match(source, /peekIncomingTradeNo/);
});

test('Check-in unique index migration exists', () => {
  const source = readSource('plugins/WalletCenter/database/migrations/2026_09_04_000004_add_unique_index_to_wallet_center_checkin_logs.php');
  assert.match(source, /wallet_center_checkin_user_date_unique/);
});

test('i18n extra locales keep {total} placeholder', () => {
  const source = readSource('theme/XboardCustom/assets/i18n-extra.js');
  assert.doesNotMatch(source, /Total \{used\}/);
  assert.match(source, /\{used\}.*\{total\}/);
});

test('Wallet overlay uses dialog semantics and theme tokens', () => {
  const source = readSource('theme/XboardCustom/assets/wallet-center.js');
  assert.match(source, /aria-modal/);
  assert.match(source, /applyThemeTokens/);
  assert.match(source, /canonicalizeWalletHash/);
  assert.match(source, /scheduleTopupPoll/);
  assert.match(source, /VUE_NAIVE_ACCESS_TOKEN/);
  assert.match(source, /state\.route = parseHash\(location\.hash\)/);
});

test('Floating dock hidden attribute actually hides the pill', () => {
  const source = readSource('theme/XboardCustom/assets/wallet-center.css');
  assert.match(source, /\.xc-wallet-dock--floating\[hidden\]/);
});
