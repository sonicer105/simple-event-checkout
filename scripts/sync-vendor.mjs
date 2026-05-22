#!/usr/bin/env node

import { cpSync, existsSync, mkdirSync, readdirSync, rmSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const scriptDir = dirname(fileURLToPath(import.meta.url));
const projectRoot = resolve(scriptDir, '..');
const nodeModulesDir = join(projectRoot, 'node_modules');
const vendorDir = join(projectRoot, 'public_html', 'assets', 'vendor');

function assertExists(path, label) {
  if (!existsSync(path)) {
    throw new Error(`${label} not found at: ${path}`);
  }
}

function copyDir(source, target) {
  rmSync(target, { recursive: true, force: true });
  mkdirSync(dirname(target), { recursive: true });
  cpSync(source, target, { recursive: true });
}

function resolveKitIconsDir() {
  const awesomeScopeDir = join(nodeModulesDir, '@awesome.me');
  const webAwesomeScopeDir = join(nodeModulesDir, '@web.awesome.me');
  assertExists(awesomeScopeDir, '@awesome.me scope');
  assertExists(webAwesomeScopeDir, '@web.awesome.me scope');

  const entries = readdirSync(awesomeScopeDir, { withFileTypes: true })
    .filter((entry) => entry.isDirectory() && /^kit-[a-z0-9]+$/i.test(entry.name))
    .map((entry) => entry.name)
    .sort();

  if (entries.length === 0) {
    throw new Error('No @awesome.me kit package found. Install one like @awesome.me/kit-<id>.');
  }

  const kitDir = join(awesomeScopeDir, entries[0]);
  const iconsDir = join(kitDir, 'icons');
  assertExists(iconsDir, 'Kit icons directory');

  return { kitName: entries[0], iconsDir };
}

function main() {
  const webawesomeSource = join(nodeModulesDir, '@web.awesome.me', 'webawesome-pro', 'dist-cdn');
  assertExists(webawesomeSource, 'Web Awesome dist-cdn');

  const { kitName, iconsDir } = resolveKitIconsDir();
  const webawesomeTarget = join(vendorDir, 'webawesome');
  const fontawesomeTarget = join(vendorDir, 'fontawesome');
  const zxingSource = join(nodeModulesDir, '@zxing', 'browser', 'umd', 'zxing-browser.min.js');
  const zxingTargetDir = join(vendorDir, 'zxing');
  const zxingTarget = join(zxingTargetDir, 'zxing-browser.min.js');

  mkdirSync(vendorDir, { recursive: true });
  copyDir(webawesomeSource, webawesomeTarget);
  copyDir(iconsDir, fontawesomeTarget);
  mkdirSync(zxingTargetDir, { recursive: true });
  if (existsSync(zxingSource)) {
    cpSync(zxingSource, zxingTarget);
    console.log(`Synced ZXing from ${zxingSource} -> ${zxingTarget}`);
  } else {
    console.log('ZXing not installed; skipping.');
  }

  console.log(`Synced Web Awesome from @web.awesome.me/webawesome-pro/dist-cdn -> ${webawesomeTarget}`);
  console.log(`Synced Font Awesome from @awesome.me/${kitName}/icons -> ${fontawesomeTarget}`);
}

main();
