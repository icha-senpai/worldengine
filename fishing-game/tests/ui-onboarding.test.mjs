import assert from 'node:assert/strict';
import { test } from 'node:test';
import { discordPlayUrl, onboardingNextSteps } from '../apps/web/src/lib/game/ui-onboarding.ts';

test('Discord destination accepts only HTTPS exact Discord hosts and safely falls back', () => {
  const fallback = 'https://discord.com/app';
  assert.equal(discordPlayUrl(undefined), fallback);
  assert.equal(discordPlayUrl(''), fallback);
  for (const value of [
    'javascript:alert(1)', 'http://discord.com/app', 'http://discord.gg/example',
    'https://discord.com.evil.example/path', 'https://evil.discord.com/path',
    'https://discord.com@evil.example/path', 'https://user:password@discord.com/path',
    'https://discord.gg:8443/invite', '//discord.gg/example', 'not a url',
  ]) assert.equal(discordPlayUrl(value), fallback, value);
  assert.equal(discordPlayUrl('https://discord.com/channels/123/456'), 'https://discord.com/channels/123/456');
  assert.equal(discordPlayUrl('https://discord.gg/example'), 'https://discord.gg/example');
});

test('next steps follow current discovery and favorite facts without remembered completion', () => {
  const facts = { discoveryCount: 0, inventoryCount: 0, hasFavorite: false };
  const ids = () => onboardingNextSteps(facts).map(row => row.id);
  assert.deepEqual(ids(), ['catch', 'favorite', 'gear']);
  assert.match(onboardingNextSteps(facts)[1].text, /Keep a fish/);
  facts.discoveryCount = 1;
  facts.inventoryCount = 1;
  assert.deepEqual(ids(), ['favorite', 'gear']);
  assert.match(onboardingNextSteps(facts)[0].text, /Favorite a keeper/);
  facts.hasFavorite = true;
  assert.deepEqual(ids(), ['gear']);
  facts.hasFavorite = false;
  facts.inventoryCount = 0;
  assert.deepEqual(ids(), ['favorite', 'gear']);
  facts.discoveryCount = 0;
  assert.deepEqual(ids(), ['catch', 'favorite', 'gear']);
  assert(onboardingNextSteps(facts).length <= 3);
});
