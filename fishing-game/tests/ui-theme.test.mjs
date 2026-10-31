import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const read = path => readFileSync(new URL(path, import.meta.url), 'utf8');
const theme = read('../apps/web/src/lib/game/theme.css');
const quests = read('../apps/web/src/lib/game/Quests.svelte');
const angler = read('../apps/web/src/lib/game/AnglerLevel.svelte');
const themeToggle = read('../apps/web/src/lib/game/ThemeToggle.svelte');
const tooltipAction = read('../apps/web/src/lib/game/tooltip.ts');
const componentFiles = [
  'Achievements.svelte', 'Anglers.svelte', 'BadgeDetails.svelte', 'DiscordPortrait.svelte', 'Gear.svelte',
  'Journal.svelte', 'Statistics.svelte', 'TitleBadge.svelte', 'Quests.svelte', 'Dashboard.svelte',
  'RaidBoard.svelte', 'RaidWorkshop.svelte', 'RaidMastery.svelte', 'FishDetails.svelte',
  'SectionTabs.svelte', 'ThemeToggle.svelte', 'AnglerLevel.svelte',
  'GameSelect.svelte', 'SearchInput.svelte', 'NumberStepper.svelte'
];
const componentSources = Object.fromEntries(componentFiles.map(name => [
  name,
  read(`../apps/web/src/lib/game/${name}`)
]));
const sourceRoot = fileURLToPath(new URL('../apps/web/src/', import.meta.url));

function webSvelteFiles(directory) {
  return readdirSync(directory, { withFileTypes: true }).flatMap(entry => {
    const path = `${directory}/${entry.name}`;
    return entry.isDirectory() ? webSvelteFiles(path) : entry.isFile() && entry.name.endsWith('.svelte') ? [path] : [];
  });
}

function styleBlock(name) {
  const match = componentSources[name].match(/<style>([\s\S]*?)<\/style>/);
  assert(match, `${name} should have a component style block`);
  return match[1];
}

function styleRule(name, selector) {
  const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const match = styleBlock(name).match(new RegExp(`(?:^|[}\\n])\\s*${escaped}\\s*\\{([^}]*)\\}`));
  assert(match, `Missing ${name} CSS selector: ${selector}`);
  return match[1];
}

function block(source, selector) {
  const start = source.indexOf(selector);
  assert.notEqual(start, -1, `Missing CSS selector: ${selector}`);
  const open = source.indexOf('{', start);
  const close = source.indexOf('}', open);
  assert(open !== -1 && close !== -1, `Malformed CSS block: ${selector}`);
  return source.slice(open + 1, close);
}

function anglerRule(selector) {
  const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const match = angler.match(new RegExp(`<style>[\\s\\S]*?${escaped}\\s*\\{([^}]*)\\}`));
  assert(match, `Missing AnglerLevel style rule: ${selector}`);
  return match[1];
}

test('non-dockside focus follows each light palette accent and dark focus follows gold', () => {
  for (const palette of ['ocean', 'rose', 'cosmic', 'synthwave']) {
    assert.match(
      block(theme, `:root[data-palette="${palette}"]`),
      /--control-focus:\s*var\(--control-accent\)\s*;/,
      `${palette} focus should track its control accent`
    );
  }
  assert.match(block(theme, ':root {'), /--control-focus:\s*#b47f35\s*;/, 'Dockside default stays unchanged');
  assert.match(block(theme, ':root[data-theme="dark"]'), /--control-focus:\s*var\(--gold\)\s*;/);
});

test('search clear artwork retains its theme-aware mask source', () => {
  const root = block(theme, ':root {');
  assert.match(root, /--search-clear-icon:\s*url\("data:image\/svg\+xml/);
  assert.equal((theme.match(/--search-clear-icon:/g) ?? []).length, 1);
});

test('shared custom controls use semantic tokens and app-owned popup semantics', () => {
  const gameSelect = componentSources['GameSelect.svelte'];
  assert.match(gameSelect, /role="combobox"/);
  assert.match(gameSelect, /aria-haspopup="listbox"/);
  assert.match(gameSelect, /popover="manual"/);
  assert.match(gameSelect, /role="listbox"/);
  assert.match(gameSelect, /role="option"/);
  assert.match(gameSelect, /aria-activedescendant=\{activeOptionId\}/);
  assert.match(styleRule('GameSelect.svelte', '.game-select-trigger:focus-visible'), /outline:3px solid var\(--control-focus\)/);
  assert.match(styleRule('GameSelect.svelte', '.game-select-option.selected'), /background:var\(--selected-background\);color:var\(--selected-ink\)/);
  assert.match(styleRule('SearchInput.svelte', '.game-search-clear span'), /background-color:var\(--ink\);-webkit-mask:var\(--search-clear-icon\)/);
  assert.match(componentSources['SearchInput.svelte'], /aria-label=\{`Clear \$\{label\}`\}/);
  assert.match(componentSources['NumberStepper.svelte'], /role="spinbutton"/);
  assert.match(componentSources['NumberStepper.svelte'], /type="text"/);
  assert.doesNotMatch(componentSources['NumberStepper.svelte'], /type="number"/);
  assert.match(tooltipAction, /setAttribute\('role', 'tooltip'\)/);
  assert.match(tooltipAction, /textContent = message/);
  assert.match(tooltipAction, /setAttribute\('aria-description'/);
  assert.doesNotMatch(tooltipAction, /innerHTML/);
});

test('quest board frame uses semantic palette edges and base surface', () => {
  const rule = block(quests.slice(quests.indexOf('<style>')), '.quest-board');
  assert.match(rule, /border:\s*3px solid var\(--paper-edge\)/);
  assert.match(rule, /box-shadow:\s*inset 0 0 0 3px var\(--paper-light\),\s*0 5px 0 var\(--site-base\)/);
});

test('AnglerLevel colors use shared palette tokens without hardcoded component colors', () => {
  const style = angler.match(/<style>([\s\S]*?)<\/style>/)?.[1];
  assert(style, 'AnglerLevel style block should exist');
  assert.doesNotMatch(style, /#[\da-f]{3,8}\b/i);
  assert.match(anglerRule('span'), /color:\s*var\(--site-text\)/);
  assert.match(anglerRule('strong'), /color:\s*var\(--site-text\)/);
  assert.match(anglerRule('strong'), /text-shadow:\s*2px 2px 0 var\(--site-base\)/);
  assert.match(anglerRule('small'), /color:\s*var\(--site-text\)/);
  assert.match(anglerRule('.total-xp'), /color:\s*var\(--site-muted\)/);
  assert.match(anglerRule('progress'), /accent-color:\s*var\(--gold\)/);
  assert.match(anglerRule('progress'), /border:\s*1px solid var\(--site-base\)/);
  assert.match(anglerRule('progress'), /background:\s*var\(--site-base\)/);
  assert.match(anglerRule('progress::-webkit-progress-bar'), /background:\s*var\(--site-base\)/);
  assert.match(anglerRule('progress::-webkit-progress-value'), /background:\s*var\(--gold\)/);
  assert.match(anglerRule('progress::-moz-progress-bar'), /background:\s*var\(--gold\)/);
});

test('shared keyboard focus selectors and ThemeToggle focus ring remain intact', () => {
  const pageControls = theme.match(/button:focus-visible,a:focus-visible,input:focus-visible,select:focus-visible\s*\{([^}]*)\}/);
  assert(pageControls, 'Buttons, links, inputs and select-compatible controls retain a shared focus rule');
  assert.match(pageControls[1], /outline:\s*3px solid var\(--control-focus\)/);
  assert.match(pageControls[1], /outline-offset:\s*4px/);

  const sharedControls = theme.match(/:where\(input,\s*select,\s*textarea,\s*summary,\s*\[role="tabpanel"\],\s*\.game-button\):focus-visible\s*\{([^}]*)\}/);
  assert(sharedControls, 'Shared form and disclosure controls retain a focus rule');
  assert.match(sharedControls[1], /outline:\s*3px solid var\(--control-focus\)/);
  assert.match(sharedControls[1], /outline-offset:\s*3px/);
  assert.match(themeToggle, /\.theme-toggle:focus-visible\{outline:3px solid var\(--control-focus\);outline-offset:4px\}/);
  assert.match(themeToggle, /\.appearance-controls\{[^}]*--control-focus:var\(--site-focus\)/);
  assert.match(componentSources['SectionTabs.svelte'], /\.section-tabs\{[^}]*--control-focus:var\(--site-focus\)/);
  assert.match(componentSources['SectionTabs.svelte'], /\.section-tabs button:focus-visible\{outline:3px solid var\(--control-focus\);outline-offset:2px\}/);
  assert.match(componentSources['Dashboard.svelte'], /nav,\.camp-tabs,footer,\.dev-login\{--control-focus:var\(--site-focus\)\}/);
  assert.match(componentSources['Dashboard.svelte'], /\.book-link:focus-visible,.view-panel:focus-visible\{outline-color:var\(--site-focus\)\}/);
  assert.doesNotMatch(componentSources['Dashboard.svelte'].match(/\.camp-banner\{[^}]*\}/)?.[0] ?? '', /--control-focus/);
});

test('shared semantic status, focus and progress tokens resolve from their theme roles', () => {
  const root = block(theme, ':root {');
  const dark = block(theme, ':root[data-theme="dark"]');
  assert.match(root, /--site-focus:\s*var\(--gold\)/);
  assert.match(root, /--site-success:\s*var\(--night-success\)/);
  assert.match(root, /--danger-ink:\s*#8b2e26/);
  assert.match(dark, /--danger-ink:\s*#ffb4a9/);
  assert.match(root, /--progress-fill:\s*var\(--control-accent\)/);
  assert.match(dark, /--progress-fill:\s*var\(--gold\)/);
  assert.match(root, /--control-check:\s*var\(--selected-ink\)/);
});

test('shared controls, custom search clear, and progress use theme tokens', () => {
  const checked = block(theme, ':where(input[type=checkbox]:checked, input[type=checkbox]:indeterminate, input[type=radio]:checked)');
  assert.match(checked, /border-color:\s*var\(--control-accent\)/);
  assert.match(checked, /box-shadow:\s*inset 1px 1px 0 var\(--panel-highlight\)/);
  assert.match(theme, /:where\(input,\s*select,\s*textarea\):user-invalid\s*\{\s*border-color:\s*var\(--danger-ink\)/);
  assert.match(theme, /\.game-button--secondary:hover:not\(:disabled\)\s*\{\s*background:\s*var\(--paper-light\)/);
  assert.match(theme, /\.game-button--secondary\s*\{\s*color:\s*var\(--ink\);\s*background:\s*var\(--panel-inset\);\s*box-shadow:\s*inset 0 0 0 2px var\(--panel-highlight\),\s*0 3px 0 var\(--wood-dark\)/);

  const cancel = block(theme, 'input[type=search]::-webkit-search-cancel-button');
  assert.match(cancel, /background-color:\s*var\(--ink\)/);
  assert.match(cancel, /-webkit-mask:\s*var\(--search-clear-icon\).*no-repeat/);
  assert.match(cancel, /mask:\s*var\(--search-clear-icon\).*no-repeat/);
  assert.doesNotMatch(cancel, /background(?:-image)?:\s*var\(--search-clear-icon\)/);
  assert.match(theme, /input\[type=search\]::-webkit-search-cancel-button\s*\{\s*appearance:\s*auto;\s*background:\s*none;\s*-webkit-mask:\s*none;\s*mask:\s*none/);

  const progress = block(theme, ':where(progress)');
  assert.match(progress, /appearance:\s*none/);
  assert.match(progress, /color:\s*var\(--progress-fill\)/);
  assert.match(progress, /accent-color:\s*var\(--progress-fill\)/);
  assert.match(progress, /border:\s*1px solid var\(--paper-edge\)/);
  assert.match(progress, /background:\s*var\(--panel-inset\)/);
  assert.match(theme, /:where\(progress\)::-webkit-progress-bar\s*\{\s*background:\s*var\(--panel-inset\)/);
  assert.match(theme, /:where\(progress\)::-webkit-progress-value\s*\{\s*background:\s*var\(--progress-fill\)/);
  assert.match(theme, /:where\(progress\)::-moz-progress-bar\s*\{\s*background:\s*var\(--progress-fill\)/);
});

test('audited component chrome uses semantic surfaces, status colors and focus contexts', () => {
  const achievement = styleBlock('Achievements.svelte');
  assert.match(achievement, /section\{[^}]*border:3px solid var\(--paper-edge\);box-shadow:inset 0 0 0 3px var\(--panel-highlight\),0 5px 0 var\(--site-base\)/);
  assert.match(achievement, /article\{[^}]*border:2px dashed var\(--paper-edge\);background:var\(--panel-inset\)/);
  assert.match(achievement, /article\.earned\{[^}]*border-color:var\(--success-ink\);background:var\(--paper-light\)/);
  assert.match(achievement, /\.seal\.illustrated:focus-visible\{outline:3px solid var\(--control-focus\)/);
  assert.match(styleRule('Achievements.svelte', 'progress'), /accent-color:var\(--progress-fill\)/);
  assert.match(styleRule('Achievements.svelte', '.seal'), /background:var\(--panel-inset\).*color:var\(--ink-muted\).*border:2px solid var\(--paper-edge\)/);
  assert.match(styleRule('Achievements.svelte', '.earned .seal'), /background:var\(--success-ink\);color:var\(--paper-light\);border-color:var\(--paper-edge\)/);
  assert.match(styleRule('Achievements.svelte', '.bonus .seal:not(.illustrated)'), /background:var\(--special-ink\);color:var\(--paper-light\)/);

  const anglers = styleBlock('Anglers.svelte');
  assert.match(anglers, /dialog::backdrop\{background:var\(--dialog-backdrop\)\}/);
  assert.match(anglers, /\.panel-header[^}]*border-bottom:2px solid var\(--paper-edge\);background:var\(--panel-inset\)/);
  assert.match(anglers, /\.profile\{border:2px solid var\(--paper-edge\);background:var\(--paper-light\)/);
  assert.match(anglers, /dl\{[^}]*border-block:1px dashed var\(--paper-edge\)/);
  assert.match(anglers, /\.badge-row \.earned-badge\{[^}]*border:1px solid var\(--success-ink\)/);
  assert.match(anglers, /\.badge-row \.earned-badge\.bonus\{[^}]*color:var\(--special-ink\);border-color:var\(--special-ink\)/);
  assert.match(anglers, /\.anglers>button\{[^}]*background:var\(--panel-inset\);color:var\(--ink\);border:2px solid var\(--paper-edge\)/);
  assert.match(anglers, /\.anglers>button\.chosen\{background:var\(--paper-light\);border-color:var\(--control-accent\)/);
  assert.match(anglers, /\.search :global\(\.game-search\)\{max-width:100%;font:inherit\}/);
  assert.doesNotMatch(anglers, /(?:^|[}\n])\s*input\s*\{/);
  assert.match(anglers, /button:focus-visible\{outline:3px solid var\(--control-focus\)/);

  assert.match(styleRule('BadgeDetails.svelte', 'dialog::backdrop'), /background:var\(--dialog-backdrop\)/);
  assert.match(styleRule('BadgeDetails.svelte', '.art'), /background:var\(--panel-texture\),var\(--art-background\);border:3px solid var\(--art-edge\);box-shadow:inset 0 0 0 3px var\(--art-highlight\)/);
  assert.match(styleRule('DiscordPortrait.svelte', '.portrait'), /background:var\(--art-background\);color:var\(--art-ink\);border:3px solid var\(--paper-edge\);box-shadow:inset 0 0 0 2px var\(--panel-highlight\)/);
  assert.match(styleRule('Journal.svelte', 'section'), /border:3px solid var\(--wood-dark\);box-shadow:inset 0 0 0 3px var\(--panel-highlight\),0 7px 0 var\(--site-base\)/);
  assert.match(styleRule('Journal.svelte', '.xp'), /color:var\(--success-ink\)/);
  assert.match(styleRule('Journal.svelte', '.error'), /color:var\(--danger-ink\)/);
  assert.match(styleRule('Statistics.svelte', '.statistics'), /background:var\(--paper-light\);border:2px solid var\(--paper-edge\);[^}]*color:var\(--ink\)/);
  assert.match(styleRule('Statistics.svelte', '.rank-totals span'), /background:var\(--panel-inset\);border:1px solid var\(--paper-edge\)/);
  assert.match(styleRule('TitleBadge.svelte', '.title-badge'), /color-mix\(in srgb,var\(--paper-light\) 88%,hsl\(var\(--title-hue\) 50% 50%\)\)/);
  assert.match(styleRule('TitleBadge.svelte', '.title-badge'), /color-mix\(in srgb,var\(--paper-edge\) 65%,hsl\(var\(--title-hue\) 50% 50%\)\)/);
  assert.match(styleRule('TitleBadge.svelte', '.title-badge'), /color:var\(--ink\)/);
  assert.match(styleRule('TitleBadge.svelte', 'button.title-badge:focus-visible'), /outline:3px solid var\(--control-focus\)/);
  assert.match(componentSources['TitleBadge.svelte'], /const hue = \$derived\(definition \? \(definition\.achievementId \* 137\.508\) % 360 : 95\)/);

  const questStyle = styleBlock('Quests.svelte');
  assert.match(questStyle, /summary:focus-visible\{outline:3px solid var\(--control-focus\)/);
  assert.match(styleRule('Quests.svelte', '.quest.claimed'), /border-color:var\(--success-ink\)/);
  assert.match(styleRule('Quests.svelte', '.progress progress'), /accent-color:var\(--progress-fill\)/);
  assert.match(styleRule('Quests.svelte', 'dialog::backdrop'), /background:var\(--dialog-backdrop\)/);

  const dashboard = styleBlock('Dashboard.svelte');
  assert.match(styleRule('Dashboard.svelte', '.notice'), /border:3px solid var\(--paper-edge\)/);
  assert.match(styleRule('Dashboard.svelte', '.notice.error'), /border-color:var\(--danger-ink\);color:var\(--danger-ink\)/);
  assert.match(styleRule('Dashboard.svelte', '.connection>span'), /background:var\(--site-muted\)/);
  assert.match(styleRule('Dashboard.svelte', '.connection.live>span'), /background:var\(--site-success\)/);
  assert.match(styleRule('Dashboard.svelte', '.biome-grid article::before'), /border-bottom:2px solid var\(--paper-edge\)/);
  assert.match(dashboard, /nav,\.camp-tabs,footer,\.dev-login\{--control-focus:var\(--site-focus\)\}/);
  assert.match(dashboard, /\.book-link:focus-visible,.view-panel:focus-visible\{outline-color:var\(--site-focus\)\}/);
  assert.doesNotMatch(dashboard.match(/\.camp-banner\{[^}]*\}/)?.[0] ?? '', /--control-focus/);

  assert.doesNotMatch(componentSources['RaidBoard.svelte'], /var\(--danger-ink\s*,/);
  assert.doesNotMatch(componentSources['RaidWorkshop.svelte'], /var\(--danger-ink\s*,/);
  assert.match(styleRule('RaidMastery.svelte', '.seals small'), /color:inherit/);
  const fishDetails = styleBlock('FishDetails.svelte');
  assert.match(styleRule('FishDetails.svelte', '.track span'), /background:var\(--progress-fill\)/);
  assert.match(fishDetails, /\.band\[data-rank=UUR\] \.track span\{background:var\(--special-ink\)\}/);
  assert.match(fishDetails, /\.band\[data-rank=UR\] \.track span,\.band\[data-rank=SSS\] \.track span\{background:var\(--control-focus\)\}/);
});

test('web templates use custom app controls without browser prompts or native popups', () => {
  for (const path of webSvelteFiles(sourceRoot)) {
    const source = readFileSync(path, 'utf8');
    const template = source
      .replace(/<script\b[^>]*>[\s\S]*?<\/script>/g, '')
      .replace(/<style\b[^>]*>[\s\S]*?<\/style>/g, '');
    assert.doesNotMatch(template, /<select\b/i, `${relative(sourceRoot, path)} has a native select`);
    assert.doesNotMatch(template, /\btitle\s*=/i, `${relative(sourceRoot, path)} has a native title tooltip`);
    assert.doesNotMatch(template, /<input\b[^>]*\btype=["']number["']/i, `${relative(sourceRoot, path)} has a native number spinner`);
    assert.doesNotMatch(template, /\/>\s*(?:(?:page|bookPage|recordPage)\s*=\s*1\s*}|placeholder\s*=)/i, `${relative(sourceRoot, path)} has a control replacement tail`);
    for (const style of source.matchAll(/<style\b[^>]*>([\s\S]*?)<\/style>/g)) {
      assert.doesNotMatch(style[1], /(?:^|[{},])\s*:global\(\.game-(?:select|search)\)/m, `${relative(sourceRoot, path)} has an unscoped global control rule`);
    }
    assert.doesNotMatch(source, /\bwindow\.(?:alert|prompt|confirm)\s*\(/);
    assert.doesNotMatch(source, /(?:^|[^\w$.])(?:alert|prompt)\s*\(/);
  }
});

test('tooltip and disabled control regressions stay guarded', () => {
  const search = componentSources['SearchInput.svelte'];
  const select = componentSources['GameSelect.svelte'];
  assert.match(search, /if \(disabled \|\| !value\) return/);
  assert.match(search, /class="game-search-clear"[^>]*\{disabled\}/);
  assert.match(select, /onclick=\{\(\) => commit\(index\)\}/);
  assert.doesNotMatch(select, /onpointerup=/);
  assert.match(componentSources['NumberStepper.svelte'], /function inputChanged[\s\S]*?pendingValue = undefined/);
  assert.equal((theme.match(/\.game-tooltip \{/g) ?? []).length, 2);
  assert.match(theme, /\.game-tooltip \{\s*position: fixed;\s*inset: auto;/);
});

test('component styles contain no unapproved raw colors outside preserved artwork and title hue', () => {
  const exceptions = {
    'Dashboard.svelte': new Map([
      ['nav|background', ['#ffffff04']],
      ['.biome-grid article::before|background', ['#456f58', '#365c52', '#7b9865', '#e8be81']],
    ]),
    'Gear.svelte': new Map([
      [':is(.rod-slot,.haul-slot)[data-quality="1"] .quality|background', ['#c8deaf', '#2b422b']],
      [':is(.rod-slot,.haul-slot)[data-quality="2"] .quality|background', ['#b5d6e8', '#253d4d']],
      [':is(.rod-slot,.haul-slot)[data-quality="3"] .quality|background', ['#d6b8e0', '#40314b']],
      [':is(.rod-slot,.haul-slot)[data-quality="4"] .quality|background', ['#edc988', '#4e3d27']],
      [':is(.rod-slot,.haul-slot)[data-quality="5"] .quality|background', ['#e8a7b5', '#4b3038']],
      [':is(.rod-slot,.haul-slot)[data-quality="6"] .quality|color', ['#382f25']],
      [':is(.rod-slot,.haul-slot)[data-quality="6"] .quality|background', ['#c6e6de', '#ddc8ec', '#edd8ae']],
    ]),
    'PondScene.svelte': new Map([
      ['.pond-art|background', ['#376c72']],
      ['.swimmer|filter', ['#163e4855']],
    ]),
  };
  const stripVars = value => {
    let output = '';
    let index = 0;
    while (index < value.length) {
      const opening = value.indexOf('var(', index);
      if (opening < 0) return output + value.slice(index);
      output += value.slice(index, opening);
      let depth = 0;
      let cursor = opening + 3;
      for (; cursor < value.length; cursor++) {
        if (value[cursor] === '(') depth++;
        else if (value[cursor] === ')' && --depth === 0) break;
      }
      index = Math.min(cursor + 1, value.length);
    }
    return output;
  };
  const findings = [];
  const colorPattern = /#[\da-f]{3,8}\b|(?:rgba?|hsla?)\s*\(|\b(?:red|white|black|green|blue|gold|purple|gray|grey|yellow|orange|pink|brown|silver|teal|maroon|navy|olive|lime|aqua|fuchsia)\b/gi;
  for (const path of webSvelteFiles(sourceRoot)) {
    const name = relative(sourceRoot, path).replaceAll('\\', '/').split('/').at(-1);
    const source = readFileSync(path, 'utf8');
    const css = source.match(/<style(?:\s[^>]*)?>([\s\S]*?)<\/style>/)?.[1];
    if (!css) continue;
    for (const [, rawSelector, declarations] of css.matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
      const selector = rawSelector.trim();
      if (selector.startsWith('@')) continue;
      for (const declaration of declarations.split(';')) {
        const separator = declaration.indexOf(':');
        if (separator < 0) continue;
        const property = declaration.slice(0, separator).trim().toLowerCase();
        if (!/(?:^|-)color$|^color$|^background(?:-color)?$|^border(?:-[\w-]+)?$|^outline(?:-[\w-]+)?$|^(?:box|text)-shadow$|^accent-color$|^filter$/.test(property)) continue;
        const value = declaration.slice(separator + 1);
        if (name === 'TitleBadge.svelte' && selector === '.title-badge' && value.includes('var(--title-hue)')) continue;
        const colors = stripVars(value).match(colorPattern) ?? [];
        const allowed = exceptions[name]?.get(`${selector}|${property}`)?.map(color => color.toLowerCase()) ?? [];
        for (const color of colors) {
          const normalized = color.toLowerCase();
          if (['transparent', 'currentcolor', 'inherit'].includes(normalized)) continue;
          if (!allowed.some(value => value === normalized)) {
            findings.push(`${relative(sourceRoot, path).replaceAll('\\', '/')} ${selector} ${property}: ${color}`);
          }
        }
      }
    }
  }
  assert.deepEqual(findings, []);
});
