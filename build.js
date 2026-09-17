#!/usr/bin/env node
/*
 * FMK Intertrade — static site builder (Phase 1)
 *
 *   template.html + content.json  ->  public/index.html
 *
 * ทำไมต้องมีขั้นตอนนี้:
 *   หน้าเว็บสาธารณะยังเป็น static ไฟล์เดียวเหมือนเดิม (เร็ว + SEO เท่าเดิม)
 *   แต่เนื้อหาทั้งหมดถูกดึงออกไปอยู่ใน content.json ซึ่งหลังบ้านจะแก้ได้
 *
 * ใช้งาน:  node build.js [ไฟล์ปลายทาง]
 *
 * หมายเหตุ: ตัวนี้เป็นเครื่องมือฝั่ง dev เท่านั้น ตอน Phase 6 จะมี PHP ที่อ่าน
 * content.json ตัวเดียวกันแล้ว generate index.html บนโฮสต์ ตรรกะต้องตรงกับไฟล์นี้
 */
'use strict';

const fs = require('fs');
const path = require('path');

const ROOT = __dirname;
const MARKER = '/*__FMK_CONTENT__*/';

function build() {
  const tplPath = path.join(ROOT, 'template.html');
  const privTplPath = path.join(ROOT, 'template-privacy.html');
  const contentPath = path.join(ROOT, 'content.json');
  /* รับที่อยู่ไฟล์ปลายทางได้ ใช้ตอนอยากสร้างไฟล์เทียบโดยไม่แตะหน้าเว็บจริง
     หน้านโยบายจะไปอยู่ข้าง ๆ ไฟล์นั้นเสมอ เพราะลิงก์ระหว่างสองหน้าเป็น path สัมพัทธ์ */
  const outPath = process.argv[2]
    ? path.resolve(process.argv[2])
    : path.join(ROOT, 'public', 'index.html');
  const privOutPath = path.join(path.dirname(outPath), 'privacy.html');

  const tpl = fs.readFileSync(tplPath, 'utf8');
  const privTpl = fs.readFileSync(privTplPath, 'utf8');
  const raw = fs.readFileSync(contentPath, 'utf8');

  let content;
  try {
    content = JSON.parse(raw);
  } catch (err) {
    throw new Error('content.json ไม่ใช่ JSON ที่ถูกต้อง: ' + err.message);
  }

  validate(content);

  if (!tpl.includes(MARKER)) {
    throw new Error('ไม่พบ ' + MARKER + ' ใน template.html');
  }
  if (!privTpl.includes(MARKER)) {
    throw new Error('ไม่พบ ' + MARKER + ' ใน template-privacy.html');
  }

  // Strip editor-only metadata and hidden items so they never ship to the browser.
  const block = (page) => {
    // `<` is escaped so the payload can never terminate the surrounding <script>.
    const json = JSON.stringify(forPublic(content, page)).replace(/</g, '\\u003c');
    return '/* ---------- Content generated from content.json — DO NOT EDIT HERE ---------- */\n' +
      'var FMK_CONTENT = ' + json + ';\n' +
      'var COMPANY = FMK_CONTENT.company;\n' +
      'var T = FMK_CONTENT.i18n;';
  };

  const html = tpl.replace(MARKER, () => block('home'));
  const priv = privTpl.replace(MARKER, () => block('privacy'));

  fs.writeFileSync(outPath, html, 'utf8');
  fs.writeFileSync(privOutPath, priv, 'utf8');
  return {
    bytes: Buffer.byteLength(html, 'utf8'),
    privacyBytes: Buffer.byteLength(priv, 'utf8'),
    langs: Object.keys(content.i18n),
  };
}

/*
 * ตัดรายการที่ซ่อนไว้ออก และลบคีย์ที่เป็นข้อมูลของหลังบ้าน
 * ต้องให้ผลตรงกับ Builder::forPublic() ใน PHP เป๊ะ ๆ — มีเทสต์เทียบไบต์ต่อไบต์อยู่
 */
function strip(node) {
  if (node === null || typeof node !== 'object') return node;

  if (Array.isArray(node)) {
    return node
      .filter((item) => !(item && typeof item === 'object' && !Array.isArray(item) && item.visible === false))
      .map(strip);
  }

  const out = {};
  for (const k of Object.keys(node)) {
    if (k === 'visible' || k.startsWith('_')) continue;
    out[k] = strip(node[k]);
  }
  return out;
}

/*
 * คีย์ที่แต่ละหน้าต้องใช้จริง
 *
 * ถ้าส่งเนื้อหาทั้งก้อนให้ทุกหน้า หน้าแรกจะแบกข้อความนโยบายอีกราว 17KB
 * ที่ไม่มีทางได้แสดงเลย ส่วนหน้านโยบายก็จะแบกเนื้อหาขายของทั้งเว็บเหมือนกัน
 * ต้องให้ผลตรงกับ Builder::PAGE_I18N ใน PHP เป๊ะ ๆ
 */
const PAGE_I18N = {
  home: (k) => k !== 'privacy',
  privacy: (k) => k === 'privacy' || k === 'footer',
};

function pickKeys(obj, keep) {
  const out = {};
  for (const k of Object.keys(obj)) if (keep(k)) out[k] = obj[k];
  return out;
}

/*
 * บทความที่ยังไม่ได้เปิดให้อ่าน ต้องไม่ส่งเนื้อหาไปหน้าเว็บเลย
 *
 * ถ้าปล่อยไป ข้อความที่ผู้ดูแลยังเขียนไม่เสร็จจะฝังอยู่ในซอร์สของหน้าเว็บจริง
 * ใครกด View Source ก็อ่านได้ และ Google ก็เก็บไปเข้าดัชนีได้
 * "ปิดอยู่" ต้องแปลว่าไม่มีใครเห็น ไม่ใช่แค่ไม่มีปุ่มให้กด
 *
 * ต้องให้ผลตรงกับ Builder::dropUnreadBodies() ใน PHP เป๊ะ ๆ
 */
function dropUnreadBodies(lang) {
  const items = lang && lang.knowledge && lang.knowledge.items;
  if (!Array.isArray(items)) return lang;
  items.forEach(function (it) {
    if (it && typeof it === 'object' && Array.isArray(it.body) && it.readable !== true) {
      it.body = [];
    }
  });
  return lang;
}

function forPublic(content, page) {
  const keep = PAGE_I18N[page] || PAGE_I18N.home;
  return {
    company: strip(content.company ?? {}),
    i18n: {
      en: pickKeys(dropUnreadBodies(strip(content.i18n?.en ?? {})), keep),
      th: pickKeys(dropUnreadBodies(strip(content.i18n?.th ?? {})), keep),
    },
  };
}

/* Fail loudly on content that would render a broken page. */
function validate(c) {
  const err = [];

  if (!c.company || typeof c.company !== 'object') err.push('ไม่มี company');
  else {
    ['address', 'phones', 'phoneHref', 'email'].forEach(function (k) {
      if (typeof c.company[k] !== 'string' || !c.company[k].trim()) err.push('company.' + k + ' ว่างหรือไม่ใช่ข้อความ');
    });
    if (!Array.isArray(c.company.social)) err.push('company.social ต้องเป็น array');
    else c.company.social.forEach(function (s, i) {
      if (!s || !s.key || !s.label || !s.href) err.push('company.social[' + i + '] ข้อมูลไม่ครบ');
    });
  }

  if (!c.i18n || !c.i18n.en || !c.i18n.th) {
    err.push('ต้องมี i18n.en และ i18n.th');
  } else {
    const en = Object.keys(c.i18n.en).sort();
    const th = Object.keys(c.i18n.th).sort();
    en.filter(function (k) { return th.indexOf(k) === -1; })
      .forEach(function (k) { err.push('i18n.th ขาดคีย์ "' + k + '"'); });
    th.filter(function (k) { return en.indexOf(k) === -1; })
      .forEach(function (k) { err.push('i18n.en ขาดคีย์ "' + k + '"'); });
  }

  if (err.length) {
    throw new Error('content.json ไม่ผ่านการตรวจ:\n  - ' + err.join('\n  - '));
  }
}

if (require.main === module) {
  try {
    const r = build();
    const out = process.argv[2] || 'public/index.html';
    console.log('build ok -> ' + out + ' (' + r.bytes + ' bytes, ภาษา: ' + r.langs.join(', ') + ')');
    console.log('          -> ' + path.join(path.dirname(out), 'privacy.html') + ' (' + r.privacyBytes + ' bytes)');
  } catch (err) {
    console.error('BUILD FAILED\n' + err.message);
    process.exit(1);
  }
}

module.exports = { build, validate, forPublic, MARKER };
