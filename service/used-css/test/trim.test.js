import { test } from 'node:test';
import assert from 'node:assert/strict';
import { parse, selectorsOf, trim } from '../lib/trim.js';
import { authorised, isPrivate, publicUrl } from '../lib/guard.js';

const CSS = `/* c */ @charset "utf-8";
:root{--a:1} .hero{color:red} .unused{color:blue} .hero,.nope{padding:1px}
.btn:hover{x:1} a::before{content:"/* not a comment */"}
@media (max-width:980px){.hero{a:b}.gone{c:d}} @media print{.gone{c:d}}
@supports (display:grid){@media screen{.hero{x:1}}}
@keyframes fade{from{opacity:0}to{opacity:1}} @keyframes spin{to{transform:rotate(1turn)}}
.hero{animation:fade 1s} .keep-me-please{k:1} .moz{-moz-appearance:none;-webkit-appearance:none}
@font-face{font-family:X;src:url(../fonts/x.woff2)} .bg{background:url("img/a.png")}`;

test('selectors are listed once, keyframe steps excluded', () => {
  const list = selectorsOf(parse(CSS));
  assert.ok(list.includes('.hero') && list.includes('.unused') && list.includes('.btn:hover'));
  assert.ok(!list.includes('from') && !list.includes('to'));
  assert.equal(list.filter((s) => s === '.hero').length, 1);
});

test('only the used selectors survive, the original text intact', () => {
  const out = trim(parse(CSS), ['.hero', '.btn:hover', 'a::before', ':root', '.moz'], ['keep-me'], 'https://example.com/wp-content/themes/t/css/style.css?ver=2');
  assert.match(out, /\.hero\{color:red\}/);
  assert.doesNotMatch(out, /\.unused|\.nope|\.gone|@media print|spin/);
  assert.match(out, /\.hero\{padding:1px\}/, 'a selector list keeps only its used selectors');
  assert.match(out, /@media \(max-width:980px\)\{\.hero\{a:b\}\s*\}/);
  assert.match(out, /@supports \(display:grid\)\{@media screen\{\.hero\{x:1\}\s*\}\s*\}/);
  assert.match(out, /@keyframes fade/, 'used keyframes stay');
  assert.match(out, /content:"\/\* not a comment \*\/"/, 'a comment-like string is untouched');
  assert.doesNotMatch(out, /\/\* c \*\//, 'real comments go');
  assert.match(out, /-moz-appearance:none;-webkit-appearance:none/, 'other browsers\' properties survive');
  assert.match(out, /\.keep-me-please/, 'the safelist keeps a selector');
  assert.match(out, /url\(https:\/\/example\.com\/wp-content\/themes\/t\/fonts\/x\.woff2\)/, 'font addresses become absolute');
  assert.doesNotMatch(out, /@charset/);
});

test('keys are checked exactly, and several can be valid', () => {
  assert.equal(authorised('Bearer abc', 'abc,def'), true);
  assert.equal(authorised('Bearer def', 'abc, def'), true);
  assert.equal(authorised('Bearer ab', 'abc'), false);
  assert.equal(authorised('', 'abc'), false);
  assert.equal(authorised('Bearer abc', ''), false);
});

test('private and internal addresses are refused', async () => {
  for (const ip of ['127.0.0.1', '10.1.2.3', '172.16.0.1', '192.168.1.1', '169.254.169.254', '100.64.0.1', '::1', 'fd00::1', '::ffff:10.0.0.1']) {
    assert.equal(isPrivate(ip), true, ip);
  }
  for (const ip of ['8.8.8.8', '104.16.0.1', '2606:4700::1']) {
    assert.equal(isPrivate(ip), false, ip);
  }
  assert.equal((await publicUrl('http://127.0.0.1/')).ok, false);
  assert.equal((await publicUrl('http://169.254.169.254/latest/meta-data')).ok, false);
  assert.equal((await publicUrl('file:///etc/passwd')).ok, false);
  assert.equal((await publicUrl('https://example.com:8443/')).ok, false);
  assert.equal((await publicUrl('https://user:pw@example.com/')).ok, false);
});
