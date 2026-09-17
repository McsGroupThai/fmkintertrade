#!/usr/bin/env node
/*
 * FMK Intertrade — regression check สำหรับ Phase 1
 *
 * พิสูจน์ว่า public/index.html ที่ build ออกมา ให้ผลเหมือน index.original.html ทุกประการ
 * โดยตรวจ 3 ชั้น:
 *
 *   1. CODE   — โค้ดส่วนที่ไม่ใช่เนื้อหา ต้อง byte-identical กับต้นฉบับ
 *   2. DATA   — ค่า COMPANY และ T ที่โค้ดมองเห็น ต้อง deep-equal กับต้นฉบับ
 *   3. RENDER — HTML ที่ render จริงทั้ง 4 กรณี (en/th × ปิด/เปิด modal) ต้องตรงกันทุกตัวอักษร
 *
 * ใช้งาน:  node verify.js
 */
'use strict';

const fs = require('fs');
const vm = require('vm');
const path = require('path');
const assert = require('assert');
/* ใช้ตัวคัดเนื้อหาตัวเดียวกับที่ build ใช้จริง จะได้ไม่เขียนตรรกะซ้ำแล้วเพี้ยนกันเอง */
const builder = require('./build.js');

const ROOT = __dirname;
const MARKER = '/*__FMK_CONTENT__*/';

const read = (f) => fs.readFileSync(path.join(ROOT, f), 'utf8');

/* ---------- helpers ---------- */

function scriptBody(html) {
  // the page has exactly one inline application <script> (the JSON-LD one is type=application/ld+json)
  const m = html.match(/<script>\n([\s\S]*?)<\/script>/);
  if (!m) throw new Error('หา <script> หลักไม่เจอ');
  return m[1];
}

/* Minimal DOM stub — enough for the page's render path, nothing more. */
function makeSandbox() {
  const nodes = {};
  const mkNode = (id) => (nodes[id] = {
    id,
    innerHTML: '',
    textContent: '',
    classList: { add() {}, remove() {}, contains() { return false; } },
    style: {},
    focus() {},
    setAttribute() {},
    getAttribute() { return null; },
    addEventListener() {},
  });
  mkNode('page');
  mkNode('layer');

  const doc = {
    // nodes are created on demand so the stub never silently returns null
    getElementById: (id) => nodes[id] || mkNode(id),
    querySelector: () => null,
    querySelectorAll: () => [],
    addEventListener() {},
    body: { style: {}, classList: { add() {}, remove() {} } },
    documentElement: { style: {} },
  };

  const sandbox = {
    document: doc,
    window: { addEventListener() {}, scrollY: 0, innerWidth: 1440, matchMedia: () => ({ matches: false }) },
    console,
    setTimeout: () => 0,
    clearTimeout: () => {},
    requestAnimationFrame: () => 0,
    IntersectionObserver: function () {
      return { observe() {}, unobserve() {}, disconnect() {} };
    },
    __nodes: nodes,
  };
  sandbox.globalThis = sandbox;
  return sandbox;
}

function loadPage(html) {
  const sandbox = makeSandbox();
  vm.createContext(sandbox);
  vm.runInContext(scriptBody(html), sandbox, { timeout: 15000, filename: 'page.js' });
  return sandbox;
}

/* Render every reachable UI combination and return one big string per page. */
function renderAll(sandbox) {
  const out = {};
  const { state } = sandbox;
  [['en', false], ['en', true], ['th', false], ['th', true]].forEach(function (combo) {
    const lang = combo[0];
    const modalOpen = combo[1];
    state.lang = lang;
    state.modalOpen = modalOpen;
    state.menuOpen = !modalOpen; // exercise the mobile menu too
    state.errors = {};
    state.submitting = false;
    state.sent = false;          // เว็บเดิมใช้ชื่อ demoNotice ตั้งทั้งคู่ไว้ให้เทียบกันได้
    state.demoNotice = false;
    state.sendError = '';
    sandbox.renderPage();
    sandbox.renderLayer();
    out[lang + (modalOpen ? '+modal' : '+menu')] = {
      page:  sandbox.__nodes.page.innerHTML,
      layer: sandbox.__nodes.layer.innerHTML,
    };
  });
  return out;
}

/* ---------- checks ---------- */

const results = [];
function check(name, fn) {
  try {
    const detail = fn();
    results.push({ name, ok: true, detail: detail || '' });
  } catch (err) {
    results.push({ name, ok: false, detail: err.message });
  }
}

const original = read('index.original.html');
const built = read('public/index.html');
const template = read('template.html');

check('1. CODE — index.html สร้างจาก template.html ล้วน ๆ', function () {
  // ตัดบล็อกเนื้อหาที่ generate ออก สิ่งที่เหลือต้องเป็น template แบบเป๊ะ ๆ
  const blockRe = /\/\* -+ Content generated from content\.json[\s\S]*?var T = FMK_CONTENT\.i18n;/;
  assert.ok(blockRe.test(built), 'ไม่พบบล็อกที่ generate ใน public/index.html');
  const stripped = built.replace(blockRe, MARKER);
  assert.strictEqual(stripped, template, 'public/index.html มีโค้ดต่างจาก template.html');

  /* template.html ไม่ได้เท่ากับต้นฉบับแบบไบต์ต่อไบต์อีกแล้ว เพราะ Phase 4 เพิ่มช่องใส่รูปเข้าไป
     สิ่งที่ยังต้องจริงเสมอคือ "ต้องไม่มีเนื้อหาฝังอยู่ใน template" — เนื้อหาทุกตัวต้องมาจาก content.json
     ส่วนการยืนยันว่าหน้าเว็บยังเหมือนเดิม เป็นหน้าที่ของข้อ 3 (RENDER) */
  /* ดูเฉพาะส่วนโค้ด JS ไม่รวม <head> เพราะ meta / OG / JSON-LD ฝังตายตัวมาตั้งแต่เว็บเดิม
     (ยังแก้ผ่านหลังบ้านไม่ได้ — บันทึกไว้เป็นงานค้างในเอกสารแล้ว) */
  const body = scriptBody(template);
  const leaked = [
    'FMK Intertrade delivers integrated',
    'Building Sustainable Growth',
    'สร้างการเติบโตที่ยั่งยืน',
    '142/36 Suksawitthaya',
    'support@fmkintertrade.com',
    'Agricultural Inputs',
  ].filter((s) => body.includes(s));
  assert.deepStrictEqual(leaked, [], 'พบเนื้อหาฝังอยู่ในโค้ดของ template.html: ' + leaked.join(' / '));

  return 'index.html = template + เนื้อหา · ไม่มีเนื้อหาฝังใน template';
});

const pageA = loadPage(original);
const pageB = loadPage(built);

/* Objects from two VM contexts have different prototypes, so deepStrictEqual would
   reject identical data. Compare by value with a key-order-independent encoding. */
function stable(v) {
  if (Array.isArray(v)) return '[' + v.map(stable).join(',') + ']';
  if (v && typeof v === 'object') {
    return '{' + Object.keys(v).sort().map((k) => JSON.stringify(k) + ':' + stable(v[k])).join(',') + '}';
  }
  return JSON.stringify(v);
}

/* Phase 4 เพิ่มช่องใส่รูปเข้ามาในโครงข้อมูล ตัดออกก่อนเทียบกับต้นฉบับ
   ที่เหลือต้องตรงกันเป๊ะ — ถ้าต่าง แปลว่าเนื้อหาถูกแก้ไป (ซึ่งอาจตั้งใจก็ได้) */
const NEW_KEYS = new Set([
  'image', 'imageAlt', 'logo', 'logoSeal', 'visible',   // Phase 4 — ช่องใส่รูป
  'showAdminLink', 'adminLabel',                        // ลิงก์เข้าหลังบ้านท้ายเว็บ

  /* Phase 5 — ฟอร์มติดต่อส่งข้อมูลจริงแล้ว ข้อความชุด "ตัวอย่าง" จึงถูกแทนที่
     ฝั่งเว็บเดิมมี demoBadge/demoTitle/demoBody ฝั่งใหม่มีชุดล่าง
     ตัดออกทั้งสองฝั่งเพื่อให้ยังเทียบข้อความอื่นในฟอร์มได้ตามปกติ
     (ป้ายชื่อช่อง ตัวเลือก และข้อความแจ้งเตือนเดิมยังถูกเทียบอยู่) */
  'demoBadge', 'demoTitle', 'demoBody',
  'privacyNote', 'sentTitle', 'sentBody', 'errServer', 'errTooMany', 'errExpired',

  /* ลิงก์ "อ่านนโยบายความเป็นส่วนตัว" ใต้ช่องยินยอม — เว็บเดิมไม่มีเพราะไม่มีนโยบายให้อ่าน */
  'privacyLink',

  /* ที่เก็บเนื้อหาเต็มของบทความ และสวิตช์เปิดให้อ่าน — เว็บเดิมไม่มีทั้งคู่
     พฤติกรรมตรวจด้วยข้อ 9 แทนการเทียบตัวอักษร */
  'readable', 'body',

  /* ข้อความบนการ์ดโซลูชัน เดิมเป็น "ดูเพิ่มเติม" ทั้งที่กดแล้วเด้งกลับมาที่เดิม
     ตอนนี้การ์ดเปิดฟอร์มติดต่อจริง ข้อความจึงเปลี่ยนเป็น "สอบถามเรื่องนี้"
     พฤติกรรมของการ์ดตรวจด้วยข้อ 7 แทนการเทียบตัวอักษร */
  'learnMore',
]);

/*
 * ลิงก์ท้ายเว็บเปลี่ยนโครงจาก "ข้อความเปล่า" เป็น {label, href}
 *
 * เว็บเดิมใส่ href="#" ให้ทุกอัน คือมีมือชี้ มีสีเปลี่ยน แต่กดแล้วไม่ไปไหน
 * โครงเดิมไม่มีที่ให้เก็บปลายทางเลย จึงแก้ให้ถูกไม่ได้ถ้าไม่เปลี่ยนโครง
 *
 * ตรงนี้เทียบเฉพาะ "ข้อความ" กับต้นฉบับ เพื่อยังจับได้ว่ามีลิงก์ไหนหายไป
 * ส่วนปลายทางเป็นของใหม่ มีเทสต์ของตัวเองอยู่ข้อ 6
 */
function flattenFooterLinks(t) {
  Object.keys(t).forEach(function (lang) {
    const f = t[lang] && t[lang].footer;
    if (!f) return;
    ['company', 'legal'].forEach(function (k) {
      if (!Array.isArray(f[k])) return;
      f[k] = f[k].map((x) => (x && typeof x === 'object' ? x.label : x));
    });
  });
  return t;
}
function withoutImageFields(v) {
  if (Array.isArray(v)) return v.map(withoutImageFields);
  if (v && typeof v === 'object') {
    const out = {};
    for (const k of Object.keys(v)) {
      if (NEW_KEYS.has(k)) continue;
      out[k] = withoutImageFields(v[k]);
    }
    return out;
  }
  return v;
}

check('2. DATA — เนื้อหาตรงกับต้นฉบับ (ยกเว้นช่องรูปที่เพิ่มเข้ามา)', function () {
  assert.strictEqual(
    stable(withoutImageFields(pageB.COMPANY)),
    stable(withoutImageFields(pageA.COMPANY)),
    'COMPANY ต่างกัน'
  );
  /* คัดลอกก่อนปรับโครง เพราะ pageB.T ตัวจริงยังต้องใช้ render ในข้อ 3 ต่อ */
  const tB = flattenFooterLinks(JSON.parse(JSON.stringify(pageB.T)));
  assert.strictEqual(
    stable(withoutImageFields(tB)),
    stable(withoutImageFields(pageA.T)),
    'T ต่างกัน'
  );
  const enKeys = Object.keys(pageB.T.en);
  const thKeys = Object.keys(pageB.T.th);
  assert.deepStrictEqual(enKeys.slice().sort(), thKeys.slice().sort(), 'คีย์ en/th ไม่ตรงกัน');
  return 'deep-equal · ' + enKeys.length + ' คีย์ต่อภาษา';
});

/*
 * คืนค่าการปรับแต่งทั้งหมดกลับเป็นค่าเริ่มต้น (ไม่มีรูป ไม่มีลิงก์หลังบ้าน แสดงทุกรายการ)
 *
 * ทำแบบนี้เพราะเมื่อผู้ดูแลเริ่มใช้งานจริง เนื้อหาจะต่างจากเว็บเดิมเป็นเรื่องปกติ
 * สิ่งที่ยังต้องจริงเสมอคือ "ถ้าเอาการปรับแต่งออกหมด หน้าเว็บต้องกลับมาเหมือนเว็บเดิมเป๊ะ"
 * ซึ่งเป็นการยืนยันว่า template ไม่ได้พังจากงานที่เพิ่มเข้าไป
 */
function neutralize(node) {
  if (Array.isArray(node)) { node.forEach(neutralize); return; }
  if (!node || typeof node !== 'object') return;
  for (const k of Object.keys(node)) {
    if (k === 'showAdminLink') { node[k] = false; continue; }
    if (k === 'visible') { node[k] = true; continue; }
    if (['image', 'imageAlt', 'logo', 'logoSeal'].includes(k) && typeof node[k] === 'string') {
      node[k] = '';
      continue;
    }
    neutralize(node[k]);
  }
}

let renderedA = null;
let renderedB = null;

/*
 * แปลงจุดที่ "ตั้งใจให้ต่าง" กลับเป็นรูปเดิม เพื่อให้ส่วนที่เหลือยังเทียบแบบไบต์ต่อไบต์ได้
 *
 * ทุกการแปลงนับจำนวนครั้งไว้ ถ้าหาไม่เจอหรือเจอไม่ครบ เทสต์จะฟ้องทันที
 * ไม่ใช่ปล่อยให้ regex ไม่ match แล้วผ่านไปเงียบ ๆ ซึ่งจะกลายเป็นเทสต์ที่ไม่ตรวจอะไรเลย
 */
function countedReplace(s, re, to, want, what) {
  let n = 0;
  const out = s.replace(re, function () { n++; return typeof to === 'function' ? to.apply(null, arguments) : to; });
  assert.strictEqual(n, want, 'คาดว่าจะแปลง ' + what + ' ' + want + ' จุด แต่เจอ ' + n + ' จุด');
  return out;
}

/* ฝั่งใหม่: การ์ดโซลูชันเป็น <button> เปิดฟอร์ม — แปลงกลับเป็น <a href="#solutions"> แบบเดิม */
function undoNewSide(html, oldLabel, newLabel) {
  let s = html;
  s = countedReplace(s, /<button type="button" data-action="open-modal" data-solution="\d+" class="sol-card"/g,
    '<a href="#solutions" class="sol-card"', 6, 'แท็กเปิดการ์ดโซลูชัน');
  s = countedReplace(s, /<\/span><\/button>/g, '</span></a>', 6, 'แท็กปิดการ์ดโซลูชัน');
  s = countedReplace(s, new RegExp(newLabel.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g'),
    oldLabel, 6, 'ข้อความบนการ์ดโซลูชัน');
  return s;
}

/* ฝั่งเดิม: ถอดปุ่ม "ดูผลงานทั้งหมด" และ "บทความทั้งหมด" ที่ลิงก์กลับมาที่หัวข้อตัวเอง */
function undoOldSide(html) {
  let s = html;
  s = countedReplace(s, /<a href="#projects" class="viewall"[\s\S]*?<\/a>/g, '', 1, 'ปุ่มดูผลงานทั้งหมด');
  s = countedReplace(s, /<a href="#knowledge" class="link-gold"[\s\S]*?<\/a>/g, '', 1, 'ปุ่มบทความทั้งหมด');
  /* คำว่า "อ่านต่อ" บนการ์ดบทความ — เว็บเดิมโชว์ไว้ทั้งสามใบทั้งที่กดไม่ได้สักใบ
     ตอนนี้จะขึ้นเฉพาะบทความที่เปิดให้อ่านแล้วเท่านั้น (margin-top:16px แยกจากการ์ดโซลูชันที่ใช้ 20px) */
  s = countedReplace(s,
    /<span style="display:inline-flex;align-items:center;gap:7px;margin-top:16px;color:#1A1A1A;font-weight:700;font-size:14px">[\s\S]*?<\/span>/g,
    '', 3, 'คำว่าอ่านต่อบนการ์ดบทความ');
  return s;
}

/* ส่วนท้ายเว็บแปลงกลับไม่ได้ เพราะรายการที่ไม่มีปลายทางถูกซ่อนไปแล้ว (ของหายไปจริง ๆ)
   จึงตัดออกจากการเทียบตัวอักษร แล้วไปตรวจพฤติกรรมที่ข้อ 6 แทน */
function beforeFooter(html) {
  const i = html.indexOf('<footer');
  assert.ok(i > 0, 'หา <footer> ในหน้าที่ render ไม่เจอ');
  return html.slice(0, i);
}

check('3. RENDER — เอาการปรับแต่งออกแล้วหน้าเว็บเหมือนเว็บเดิม (ยกเว้นจุดที่ตั้งใจเปลี่ยน)', function () {
  neutralize(pageB.COMPANY);
  neutralize(pageB.T);

  renderedA = renderAll(pageA);
  renderedB = renderAll(pageB);
  const cases = Object.keys(renderedA);

  const labels = {
    en: ['Learn more', 'Ask about this'],
    th: ['ดูเพิ่มเติม', 'สอบถามเรื่องนี้'],
  };

  cases.forEach(function (k) {
    const a = renderedA[k];
    const b = renderedB[k];
    const lang = k.split('+')[0];
    assert.ok(a.page.length > 5000, 'กรณี ' + k + ' render ออกมาสั้นผิดปกติ (' + a.page.length + ' ตัวอักษร)');

    /* ตั้งแต่หัวเว็บถึงก่อนส่วนท้าย ต้องเหมือนเว็บเดิมทุกตัวอักษร
       หลังจากแปลงจุดที่ตั้งใจเปลี่ยนกลับแล้ว */
    const pageA2 = beforeFooter(undoOldSide(a.page));
    const pageB2 = beforeFooter(undoNewSide(b.page, labels[lang][0], labels[lang][1]));
    assert.strictEqual(pageB2, pageA2, 'เนื้อหาหน้าเว็บต่างกันในกรณี ' + k);

    /* ชั้นที่ลอยทับ: เมนูมือถือยังต้องเหมือนเดิมเป๊ะ
       ส่วนหน้าต่างฟอร์มติดต่อ "ตั้งใจให้ต่าง" เพราะเปลี่ยนจากตัวอย่างเป็นของจริง
       จึงย้ายไปตรวจด้วยข้อ 5 แทนการเทียบตัวอักษร */
    if (!k.endsWith('+modal')) {
      assert.strictEqual(b.layer, a.layer, 'เมนูมือถือต่างกันในกรณี ' + k);
    }
  });

  const sample = Math.round(cases.reduce((n, k) => n + renderedA[k].page.length, 0) / cases.length);
  return cases.length + ' กรณี · หน้าเว็บเฉลี่ย ' + sample.toLocaleString() + ' ตัวอักษร ตรงกันหมด'
       + ' · เมนูมือถือตรงกัน (ส่วนท้ายดูข้อ 6 · การ์ดโซลูชันดูข้อ 7 · ฟอร์มดูข้อ 5)';
});

check('5. ฟอร์มติดต่อ — ส่งข้อมูลจริง ไม่ใช่ตัวอย่าง', function () {
  assert.ok(renderedB !== null, 'ข้อ 3 ต้องผ่านก่อน');

  /* ช่องกรอกทุกช่องของเว็บเดิมต้องยังอยู่ครบ — ห้ามมีช่องไหนหายไปตอนแปลงเป็นของจริง */
  const need = ['fullName', 'company', 'position', 'country', 'email', 'phone',
                'solution', 'projectType', 'message', 'contactMethod', 'consent'];
  ['en+modal', 'th+modal'].forEach(function (k) {
    const oldHtml = renderedA[k].layer;
    const newHtml = renderedB[k].layer;

    need.forEach(function (name) {
      assert.ok(new RegExp('name="' + name + '"').test(oldHtml), 'เว็บเดิมไม่มีช่อง ' + name + ' (' + k + ')');
      assert.ok(new RegExp('name="' + name + '"').test(newHtml), 'ช่อง ' + name + ' หายไป (' + k + ')');
    });

    // ช่องล่อบอทต้องมี และต้องมองไม่เห็น + Tab ไม่ถึง
    assert.ok(/name="website"/.test(newHtml), 'ไม่มีช่องล่อบอท (' + k + ')');
    assert.ok(/tabindex="-1"/.test(newHtml), 'ช่องล่อบอทยัง Tab ถึงได้ (' + k + ')');
    assert.ok(/left:-9999px/.test(newHtml), 'ช่องล่อบอทยังมองเห็นได้ (' + k + ')');

    // ข้อความที่บอกว่าเป็นตัวอย่างต้องไม่เหลืออยู่แล้ว
    assert.ok(/ตัวอย่าง|demo|Demo/.test(oldHtml), 'เว็บเดิมควรมีคำว่าตัวอย่าง (' + k + ')');
    assert.ok(!/แบบฟอร์มตัวอย่าง|Demo form|no data was sent/i.test(newHtml),
      'ยังมีข้อความบอกว่าเป็นแบบฟอร์มตัวอย่างค้างอยู่ (' + k + ')');

    // หัวข้อและป้ายชื่อช่องต้องยังเป็นข้อความชุดเดิม
    assert.ok(newHtml.includes('consult-title'), 'หัวข้อหน้าต่างหายไป (' + k + ')');
  });

  /* ตัวส่งจริงต้องอยู่ใน template และตัวอย่างเดิมต้องถูกถอดออกหมด */
  assert.ok(template.includes("fetch('contact.php'"), 'template ไม่ได้ส่งฟอร์มไปที่ contact.php');
  assert.ok(!/DEMO ONLY/.test(template), 'ยังมีคอมเมนต์ DEMO ONLY ค้างอยู่ใน template');
  assert.ok(!/state\.demoNotice\s*=\s*true/.test(template), 'ยังตั้งสถานะตัวอย่างอยู่ใน template');
  assert.ok(fs.existsSync(path.join(ROOT, 'public', 'contact.php')), 'ไม่มีไฟล์ public/contact.php');

  return 'ช่องกรอกครบ ' + need.length + ' ช่อง · มีช่องล่อบอท · ส่งไป contact.php จริง';
});

check('4. ความสมบูรณ์ของเนื้อหา — ไม่มีข้อความหาย', function () {
  const content = JSON.parse(read('content.json'));
  const flat = (o, acc) => {
    if (typeof o === 'string') acc.push(o);
    else if (Array.isArray(o)) o.forEach((v) => flat(v, acc));
    else if (o && typeof o === 'object') Object.values(o).forEach((v) => flat(v, acc));
    return acc;
  };
  const strings = flat(content.i18n, []).concat(flat(content.company, []));
  assert.ok(strings.length > 300, 'สกัดข้อความได้แค่ ' + strings.length + ' ชิ้น น่าจะมีอะไรหาย');
  const thai = strings.filter((s) => /[฀-๿]/.test(s)).length;
  return strings.length + ' ข้อความ (ไทย ' + thai + ' ชิ้น)';
});

check('6. ลิงก์ท้ายเว็บ — ไม่มีลิงก์ที่กดแล้วไม่ไปไหน', function () {
  assert.ok(renderedB !== null, 'ข้อ 3 ต้องผ่านก่อน');
  const footer = function (html) {
    const i = html.indexOf('<footer');
    assert.ok(i > 0, 'หา <footer> ไม่เจอ');
    return html.slice(i);
  };

  ['en+menu', 'th+menu'].forEach(function (k) {
    /* ยืนยันก่อนว่าปัญหาเคยมีอยู่จริง ไม่งั้นเทสต์นี้อาจผ่านเพราะไม่มีอะไรให้ตรวจตั้งแต่แรก */
    assert.ok(/href="#"/.test(footer(renderedA[k].page)),
      'เว็บเดิมควรมีลิงก์ href="#" ในส่วนท้าย (' + k + ') — ถ้าไม่มี แปลว่าเทสต์นี้ตรวจผิดที่');

    const f = footer(renderedB[k].page);
    assert.ok(!/href="#"/.test(f), 'ยังมีลิงก์ href="#" ค้างอยู่ในส่วนท้าย (' + k + ')');
    assert.ok(!/href=""/.test(f), 'มีลิงก์ที่ปลายทางว่างในส่วนท้าย (' + k + ')');
  });

  assert.ok(!/href="#"/.test(renderedB['th+menu'].page), 'ยังมีลิงก์ href="#" อยู่ที่อื่นในหน้า');

  const th = footer(renderedB['th+menu'].page);
  assert.ok(th.includes('href="#about"'), 'ลิงก์ "เกี่ยวกับ FMK" ในส่วนท้ายหายไป');
  assert.ok(th.includes('href="#contact"'), 'ลิงก์ "ติดต่อ" ในส่วนท้ายหายไป');
  assert.ok(th.includes('นโยบายความเป็นส่วนตัว'), 'ลิงก์นโยบายความเป็นส่วนตัวในส่วนท้ายหายไป');

  /* รายการที่ยังไม่มีหน้ารองรับต้องไม่แสดง — ข้อความที่กดไม่ได้แย่กว่าไม่มีเลย
     สามรายการนี้ยังไม่มีหน้าจริง ต่างจากนโยบายความเป็นส่วนตัวที่ทำหน้าแล้ว */
  ['ผู้บริหาร', 'ร่วมงานกับเรา', 'เงื่อนไขการใช้งาน', 'นโยบายคุกกี้', 'แผนผังเว็บไซต์'].forEach(function (w) {
    assert.ok(!th.includes(w), 'รายการที่ยังไม่มีปลายทางไม่ควรแสดงในส่วนท้าย: ' + w);
  });

  return 'ไม่มี href="#" เหลือทั้งหน้า · ส่วนท้ายเหลือเฉพาะลิงก์ที่มีปลายทางจริง';
});

check('7. การ์ดโซลูชัน — กดแล้วเปิดฟอร์มพร้อมเลือกโซลูชันไว้ให้', function () {
  const page = renderedB['th+menu'].page;
  for (let i = 1; i <= 6; i++) {
    assert.ok(page.includes('data-solution="' + i + '"'), 'ไม่พบการ์ดโซลูชันใบที่ ' + i);
  }
  assert.ok(!page.includes('<a href="#solutions" class="sol-card"'),
    'ยังมีการ์ดโซลูชันที่เป็นลิงก์กลับมาที่หัวข้อตัวเอง');

  const solSelect = function (html) {
    const m = html.match(/<select id="f-solution"[\s\S]*?<\/select>/);
    assert.ok(m, 'หาช่องเลือกโซลูชันในฟอร์มไม่เจอ');
    return m[0];
  };

  /* ตรวจพฤติกรรมจริง ไม่ใช่แค่ดูว่ามีปุ่มอยู่
     โหลดหน้าใหม่แยกต่างหาก จะได้ไม่ไปกวนสถานะที่ข้ออื่นใช้อยู่ */
  const p = loadPage(built);
  p.state.lang = 'th';
  p.openModal(3);
  const wantTh = p.T.th.form.solutionOpts[3];
  assert.ok(solSelect(p.__nodes.layer.innerHTML).includes('value="' + wantTh + '" selected'),
    'เปิดฟอร์มจากการ์ดใบที่ 3 แล้วช่องโซลูชันไม่ได้เลือก "' + wantTh + '" ไว้ให้');

  /* สลับภาษาแล้วต้องยังเลือกโซลูชันเดิม — ถ้าเก็บเป็นข้อความไทยตรง ๆ ตรงนี้จะพัง */
  p.setLang('en');
  const wantEn = p.T.en.form.solutionOpts[3];
  assert.ok(solSelect(p.__nodes.layer.innerHTML).includes('value="' + wantEn + '" selected'),
    'สลับเป็นภาษาอังกฤษแล้วช่องโซลูชันหลุดจาก "' + wantEn + '"');

  /* เปิดจากปุ่มทั่วไป (ไม่ได้มาจากการ์ด) ต้องไม่เลือกโซลูชันอะไรไว้ให้ */
  p.openModal();
  assert.ok(solSelect(p.__nodes.layer.innerHTML).includes('<option value="" selected>'),
    'เปิดฟอร์มจากปุ่มทั่วไปแล้วกลับมีโซลูชันถูกเลือกไว้');

  return 'การ์ด 6 ใบเปิดฟอร์มได้ · เลือกโซลูชันให้ถูกใบ · สลับภาษาแล้วยังอยู่';
});

/*
 * หน้านโยบายใช้แม่แบบคนละไฟล์และรับเนื้อหาคนละชุดกับหน้าแรก
 * สคริปต์ของมันเรียก location / navigator / history ซึ่ง DOM จำลองตัวเดิมไม่มี
 */
function loadPrivacy(html, search) {
  const sandbox = makeSandbox();
  sandbox.location = { search: search || '' };
  sandbox.navigator = { language: 'th-TH' };
  sandbox.history = { replaceState() {} };
  vm.createContext(sandbox);
  vm.runInContext(scriptBody(html), sandbox, { timeout: 15000, filename: 'privacy.js' });
  return sandbox;
}

check('8. หน้านโยบายความเป็นส่วนตัว', function () {
  const privPath = path.join(ROOT, 'public', 'privacy.html');
  assert.ok(fs.existsSync(privPath), 'build แล้วไม่มี public/privacy.html');
  const priv = read('public/privacy.html');

  /* หน้าแรกต้องไม่แบกข้อความนโยบายไปด้วย และหน้านโยบายต้องไม่แบกเนื้อหาขายของ
     ทั้งสองหน้าโหลดจาก content ก้อนเดียวกัน ถ้าไม่คัดคีย์ ทั้งคู่จะบวมโดยไม่มีใครสังเกต */
  assert.ok(!('privacy' in pageB.T.th), 'หน้าแรกยังมีข้อความนโยบายฝังอยู่');
  assert.ok(!('privacy' in pageB.T.en), 'หน้าแรกยังมีข้อความนโยบายฝังอยู่ (en)');

  const p = loadPrivacy(priv, '?lang=th');
  assert.ok('privacy' in p.T.th, 'หน้านโยบายไม่มีเนื้อหานโยบาย');
  assert.ok(!('hero' in p.T.th), 'หน้านโยบายยังแบกเนื้อหาหน้าแรกไปด้วย');
  assert.ok('footer' in p.T.th, 'หน้านโยบายต้องมี footer ไว้แสดงข้อความลิขสิทธิ์');

  const th = p.__nodes.page.innerHTML;
  assert.ok(th.length > 2000, 'หน้านโยบาย render ออกมาสั้นผิดปกติ (' + th.length + ' ตัวอักษร)');
  assert.ok(th.includes(p.T.th.privacy.title), 'ไม่พบชื่อหน้านโยบาย');
  assert.ok(th.includes(p.T.th.privacy.updated), 'ไม่พบวันที่ปรับปรุงล่าสุด');

  /* หัวข้อต้องขึ้นครบทุกข้อ — นโยบายที่แสดงไม่ครบแย่กว่าไม่มีนโยบาย */
  p.T.th.privacy.sections.forEach(function (s) {
    assert.ok(th.includes(s.heading), 'หัวข้อ "' + s.heading + '" ไม่ขึ้นบนหน้า');
    (s.items || []).forEach(function (it) {
      assert.ok(th.includes(it), 'รายการย่อย "' + it.slice(0, 24) + '..." ไม่ขึ้นบนหน้า');
    });
  });

  assert.ok(!/href="#"/.test(th), 'หน้านโยบายมีลิงก์ที่กดแล้วไม่ไปไหน');
  assert.ok(th.includes('index.html?lang=th'), 'ลิงก์กลับหน้าแรกไม่ได้ส่งภาษาไปด้วย');

  /* สลับเป็นอังกฤษแล้วต้องเป็นข้อความชุดอังกฤษจริง ไม่ใช่ไทยค้าง */
  p.lang = 'en';
  p.render();
  const en = p.__nodes.page.innerHTML;
  assert.ok(en.includes(p.T.en.privacy.title), 'สลับเป็นอังกฤษแล้วชื่อหน้าไม่เปลี่ยน');
  assert.ok(!en.includes(p.T.th.privacy.sections[0].heading), 'สลับภาษาแล้วยังมีหัวข้อไทยค้างอยู่');
  assert.ok(en.includes('index.html?lang=en'), 'ลิงก์กลับหน้าแรกไม่ได้เปลี่ยนตามภาษา');

  /* ไม่มี ?lang= มาให้ ต้องเดาจากภาษาเบราว์เซอร์ ไม่ใช่พังหรือขึ้นหน้าว่าง */
  const auto = loadPrivacy(priv, '');
  assert.strictEqual(auto.lang, 'th', 'เบราว์เซอร์ภาษาไทยแต่หน้ากลับไม่ขึ้นภาษาไทย');

  /* หน้าแรกต้องมีทางไปถึงหน้านโยบายจริง ทั้งจากท้ายเว็บและจากใต้ช่องยินยอมในฟอร์ม */
  const home = renderedB['th+menu'];
  assert.ok(home.page.includes('privacy.html?lang=th'), 'ท้ายเว็บไม่มีลิงก์ไปหน้านโยบาย');
  assert.ok(renderedB['th+modal'].layer.includes('privacy.html?lang=th'),
    'ในฟอร์มติดต่อไม่มีลิงก์ให้กดอ่านนโยบาย ทั้งที่ข้อความยินยอมอ้างถึง');

  return p.T.th.privacy.sections.length + ' หัวข้อ · สองภาษา · '
       + Math.round(fs.statSync(privPath).size / 1024) + ' KB';
});

/* ประกอบหน้าเว็บจากเนื้อหาชุดที่กำหนดเอง ใช้ตัวคัดเนื้อหาตัวเดียวกับ build.js
   เพื่อทดลองสถานะที่ยังไม่มีในเนื้อหาจริง เช่นบทความที่เปิดให้อ่านแล้ว */
function withContent(content) {
  const json = JSON.stringify(builder.forPublic(content, 'home')).replace(/</g, '\\u003c');
  return template.replace(MARKER,
    '/* ---------- Content generated from content.json — DO NOT EDIT HERE ---------- */\n' +
    'var FMK_CONTENT = ' + json + ';\n' +
    'var COMPANY = FMK_CONTENT.company;\n' +
    'var T = FMK_CONTENT.i18n;');
}

check('9. บทความ "อ่านต่อ" — ปิดอยู่จนกว่าจะมีเนื้อหาจริง', function () {
  const draft = JSON.parse(read('content.json'));
  const items = draft.i18n.th.knowledge.items;

  /* สถานะตั้งต้นต้องเป็น "ปิด" ทุกชิ้น เพราะบทความบนเว็บยังเป็นข้อความตัวอย่าง */
  items.forEach(function (a, i) {
    assert.strictEqual(a.readable, false, 'บทความที่ ' + (i + 1) + ' ควรปิดไว้ตั้งแต่ต้น');
    assert.ok(Array.isArray(a.body), 'บทความที่ ' + (i + 1) + ' ไม่มีช่องเนื้อหาเต็ม');
  });

  const page = renderedB['th+menu'].page;
  assert.ok(!page.includes('data-action="open-article"'), 'ปิดอยู่แต่การ์ดกลับกดได้');
  assert.ok(!page.includes(draft.i18n.th.knowledge.readMore),
    'ปิดอยู่แต่ยังโชว์คำว่า "' + draft.i18n.th.knowledge.readMore + '" บนการ์ด');

  /* เปิดหนึ่งชิ้นแล้วสร้างหน้าใหม่ — ต้องกดได้ และหน้าต่างต้องมีเนื้อหาครบ */
  const secret = 'ย่อหน้าทดสอบที่ยังไม่ควรหลุดออกไป';
  const on = JSON.parse(JSON.stringify(draft));
  ['en', 'th'].forEach(function (L) {
    on.i18n[L].knowledge.items[1].readable = true;
    on.i18n[L].knowledge.items[1].body = [secret, 'ย่อหน้าที่สอง'];
    /* ชิ้นที่ 0 มีเนื้อหาแต่ยังปิดอยู่ ใช้พิสูจน์ว่าเนื้อหาที่ยังไม่เปิดไม่หลุดออกไป */
    on.i18n[L].knowledge.items[0].body = ['ข้อความลับที่ยังไม่เผยแพร่'];
  });

  const built2 = withContent(on);

  /* เนื้อหาของบทความที่ยังปิดอยู่ ต้องไม่อยู่ในหน้าเว็บเลยแม้แต่ในซอร์ส */
  assert.ok(!built2.includes('ข้อความลับที่ยังไม่เผยแพร่'),
    'เนื้อหาบทความที่ยังปิดอยู่หลุดไปอยู่ในหน้าเว็บจริง');
  assert.ok(built2.includes(secret), 'เนื้อหาบทความที่เปิดแล้วกลับไม่ถูกส่งไปหน้าเว็บ');

  const p2 = loadPage(built2);
  p2.state.lang = 'th';
  p2.renderPage();
  const page2 = p2.__nodes.page.innerHTML;
  assert.ok(page2.includes('data-article="1"'), 'เปิดแล้วแต่การ์ดยังกดไม่ได้');
  assert.ok(!page2.includes('data-article="0"'), 'บทความที่ยังปิดอยู่กลับกดได้');
  assert.ok(!page2.includes('data-article="2"'), 'บทความที่ยังปิดอยู่กลับกดได้');

  p2.openArticle(1);
  const layer2 = p2.__nodes.layer.innerHTML;
  assert.ok(layer2.includes(on.i18n.th.knowledge.items[1].title), 'หน้าต่างบทความไม่มีชื่อเรื่อง');
  assert.ok(layer2.includes(secret), 'หน้าต่างบทความไม่มีย่อหน้าที่ใส่ไว้');
  assert.ok(layer2.includes('ย่อหน้าที่สอง'), 'หน้าต่างบทความแสดงย่อหน้าไม่ครบ');

  /* กดบทความที่ปิดอยู่ (เช่นลิงก์เก่าที่ส่งต่อกันมา) ต้องไม่เปิดหน้าต่างเปล่า */
  p2.closeArticle();
  p2.openArticle(0);
  assert.strictEqual(p2.state.articleIdx, -1, 'เปิดบทความที่ยังปิดอยู่ได้ ทั้งที่ไม่ควรเปิดได้');

  /* ติ๊กเปิดแต่ยังไม่ได้พิมพ์เนื้อหา ต้องไม่มีปุ่มให้กด ดีกว่ากดแล้วเจอหน้าต่างเปล่า */
  const empty = JSON.parse(JSON.stringify(draft));
  ['en', 'th'].forEach(function (L) { empty.i18n[L].knowledge.items[1].readable = true; });
  const p3 = loadPage(withContent(empty));
  p3.state.lang = 'th';
  p3.renderPage();
  assert.ok(!p3.__nodes.page.innerHTML.includes('data-action="open-article"'),
    'เปิดสวิตช์ไว้แต่ยังไม่มีย่อหน้า การ์ดไม่ควรกดได้');

  return items.length + ' บทความ · ปิดไว้ทั้งหมด · เปิดแล้วกดอ่านได้ · เนื้อหาที่ปิดอยู่ไม่หลุดออกหน้าเว็บ';
});

/* ---------- report ---------- */

console.log('');
let failed = 0;
results.forEach(function (r) {
  console.log((r.ok ? '  PASS  ' : '  FAIL  ') + r.name + (r.detail ? '\n          ' + r.detail : ''));
  if (!r.ok) failed++;
});
console.log('');
console.log(failed === 0 ? 'ผ่านทั้งหมด — index.html ที่ build ให้ผลเหมือนต้นฉบับ' : failed + ' รายการไม่ผ่าน');
process.exit(failed === 0 ? 0 : 1);
