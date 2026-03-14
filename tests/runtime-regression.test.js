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

test('Wallet dock display strategy keeps mobile dashboard dock hidden until sidebar is ready', () => {
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
    { mode: 'floating', visible: false, probe: true, floatingAllowed: false }
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
