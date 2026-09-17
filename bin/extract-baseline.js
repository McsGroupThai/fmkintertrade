#!/usr/bin/env node
/*
 * ดึงเนื้อหาต้นฉบับจาก index.original.html ออกมาเป็น baseline.json
 *
 *   node bin/extract-baseline.js
 *
 * baseline.json คือ "เนื้อหาของเว็บเดิมก่อนมีระบบหลังบ้าน" ใช้เป็นหลักอ้างอิงถาวร
 *
 * ต่างจาก content.json อย่างไร:
 *   content.json  = สำเนาของเนื้อหาปัจจุบัน ถูกเขียนทับทุกครั้งที่กดเผยแพร่
 *   baseline.json = เนื้อหาเว็บเดิม ไม่เปลี่ยนเลย ใช้เทียบและใช้กู้คืน
 *
 * เคยพลาดมาแล้ว: สคริปต์กู้คืนไปอ่าน content.json เป็นต้นแบบ ซึ่งตอนนั้นมีข้อความ
 * ที่ชุดทดสอบเขียนทิ้งไว้อยู่ด้วย จึง "กู้" กลับมาเป็นข้อความทดสอบเหมือนเดิม
 */
'use strict';

const fs = require('fs');
const vm = require('vm');
const path = require('path');

const ROOT = path.dirname(__dirname);
const src = fs.readFileSync(path.join(ROOT, 'index.original.html'), 'utf8');
const lines = src.split('\n');

const grab = (from, to) => lines.slice(from - 1, to).join('\n');

const companySrc = grab(123, 134);   // var COMPANY = { ... };
const tSrc = grab(169, 252);         // var T = { ... };

if (!companySrc.startsWith('var COMPANY = {') || !companySrc.trimEnd().endsWith('};')) {
  throw new Error('ขอบเขตของบล็อก COMPANY ไม่ตรง — index.original.html ถูกแก้หรือเปล่า?');
}
if (!tSrc.startsWith('var T = {') || !tSrc.trimEnd().endsWith('};')) {
  throw new Error('ขอบเขตของบล็อก T ไม่ตรง — index.original.html ถูกแก้หรือเปล่า?');
}

const ctx = {};
vm.createContext(ctx);
vm.runInContext(companySrc + '\n' + tSrc, ctx, { timeout: 5000 });

if (!ctx.COMPANY || !ctx.T || !ctx.T.en || !ctx.T.th) {
  throw new Error('สกัดข้อมูลออกมาได้รูปทรงผิดคาด');
}

const out = {
  _comment: 'เนื้อหาเว็บเดิมก่อนมีระบบหลังบ้าน — ห้ามแก้ด้วยมือ สร้างใหม่ด้วย node bin/extract-baseline.js',
  _source: 'index.original.html',
  company: ctx.COMPANY,
  i18n: { en: ctx.T.en, th: ctx.T.th },
};

const dest = path.join(ROOT, 'baseline.json');
fs.writeFileSync(dest, JSON.stringify(out, null, 2) + '\n', 'utf8');

const enKeys = Object.keys(ctx.T.en);
const thKeys = Object.keys(ctx.T.th);
console.log('baseline.json สร้างแล้ว');
console.log('  คีย์ต่อภาษา : ' + enKeys.length);
console.log('  ตรงกันสองภาษา : ' + (JSON.stringify(enKeys) === JSON.stringify(thKeys) ? 'ใช่' : 'ไม่ใช่'));
console.log('  ขนาด : ' + fs.statSync(dest).size + ' bytes');
