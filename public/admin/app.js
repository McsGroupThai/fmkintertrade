/*
 * หลังบ้าน FMK — สคริปต์ฝั่งหน้าจอ
 *
 * ออกแบบโดยยึดว่า "ถ้าสคริปต์นี้ไม่ทำงาน ทุกอย่างต้องยังใช้ได้"
 * การอัปโหลดแบบลากวางและตัวเลือกรูปเป็นแค่ความสะดวกที่เพิ่มเข้ามา
 * ฟอร์มธรรมดายังส่งได้ตามปกติถ้า JavaScript ถูกปิด
 */
(function () {
  'use strict';

  var csrf = (document.querySelector('#csrfholder input[name="csrf"]') ||
              document.querySelector('input[name="csrf"]') || {}).value || '';

  /* ================================================================
     กล่องยืนยันของระบบเอง
     ----------------------------------------------------------------
     เดิมใช้ confirm() ของเบราว์เซอร์ ซึ่งขึ้นหัวว่า "localhost:8080 says"
     ดูเหมือนข้อความเตือนของเบราว์เซอร์มากกว่าส่วนหนึ่งของระบบ
     และจัดรูปแบบอะไรไม่ได้เลย — ปุ่มลบกับปุ่มยืนยันหน้าตาเหมือนกันหมด

     กล่องนี้ใช้แทนสำหรับ "การกระทำ" ทั้งหมด (ลบ ตั้งรหัสใหม่ เตะออก)
     ส่วนคำเตือนตอนออกจากหน้ายังเป็นของเบราว์เซอร์อยู่ เพราะตอนปิดแท็บ
     เบราว์เซอร์บังคับใช้หน้าต่างของตัวเองเสมอ แต่งไม่ได้
     ================================================================ */

  var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), ' +
                  'select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

  /** ของที่กด Tab ไปถึงได้จริงภายในกรอบที่กำหนด (ตัดตัวที่ถูกซ่อนออก) */
  function stopsIn(box) {
    if (!box) return [];
    return Array.prototype.filter.call(box.querySelectorAll(FOCUSABLE), function (el) {
      return el.offsetWidth > 0 || el.offsetHeight > 0 || el === document.activeElement;
    });
  }

  /** ขัง Tab / Shift+Tab ไว้ในกรอบ — ใช้ร่วมกันทั้งกล่องยืนยันและหน้าต่างเลือกรูป */
  function trapTab(box, ev) {
    var stops = stopsIn(box);
    if (stops.length === 0) {
      ev.preventDefault();
      box.focus();
      return;
    }
    var first = stops[0];
    var last = stops[stops.length - 1];
    var here = document.activeElement;

    if (!box.contains(here)) {
      ev.preventDefault();
      (ev.shiftKey ? last : first).focus();
    } else if (ev.shiftKey && here === first) {
      ev.preventDefault();
      last.focus();
    } else if (!ev.shiftKey && here === last) {
      ev.preventDefault();
      first.focus();
    }
  }

  var dlg = null, dlgBox = null, dlgAnswer = null, dlgOpener = null, dlgOverflow = '';

  /* สร้างด้วยคำสั่ง DOM ไม่ใช่ innerHTML — ข้อความทั้งหมดใส่ผ่าน textContent
     จึงไม่มีทางที่ข้อความบนปุ่มจะกลายเป็น HTML ไปได้ */
  function el(tag, attrs, text) {
    var n = document.createElement(tag);
    for (var k in attrs) {
      if (Object.prototype.hasOwnProperty.call(attrs, k)) n.setAttribute(k, attrs[k]);
    }
    if (text) n.textContent = text;
    return n;
  }

  function buildDialog() {
    if (dlg) return;

    var title = el('h2', { id: 'cdlgtitle' });
    var msg   = el('p',  { id: 'cdlgmsg' });
    var no    = el('button', { type: 'button', class: 'secondary', 'data-cdlg': 'no' });
    var yes   = el('button', { type: 'button', 'data-cdlg': 'yes' });

    var btns = el('div', { class: 'cbtns' });
    btns.appendChild(no);
    btns.appendChild(yes);

    dlgBox = el('div', {
      class: 'cbox', role: 'alertdialog', 'aria-modal': 'true',
      'aria-labelledby': 'cdlgtitle', 'aria-describedby': 'cdlgmsg', tabindex: '-1',
    });
    dlgBox.appendChild(title);
    dlgBox.appendChild(msg);
    dlgBox.appendChild(btns);

    dlg = el('div', { class: 'cdialog' });
    dlg.hidden = true;
    dlg.appendChild(dlgBox);
    document.body.appendChild(dlg);
  }

  /**
   * ถามยืนยัน คืน Promise ที่ได้ true/false
   * @param {{message:string, title?:string, okLabel?:string, danger?:boolean, opener?:Object}} o
   */
  function askConfirm(o) {
    buildDialog();
    return new Promise(function (resolve) {
      dlgAnswer = resolve;
      dlgOpener = o.opener || null;

      dlg.querySelector('#cdlgtitle').textContent = o.title || 'ยืนยันการทำรายการ';
      /* ใส่ด้วย textContent ไม่ใช่ innerHTML — ข้อความมาจากแอตทริบิวต์บนหน้า
         การขึ้นบรรทัดใหม่ให้ CSS จัดการด้วย white-space:pre-line */
      dlg.querySelector('#cdlgmsg').textContent = o.message || '';

      var yes = dlg.querySelector('[data-cdlg="yes"]');
      yes.textContent = o.okLabel || 'ยืนยัน';
      yes.setAttribute('class', o.danger ? 'primary danger' : 'primary');
      dlg.querySelector('[data-cdlg="no"]').textContent = o.cancelLabel || 'ยกเลิก';

      dlg.hidden = false;
      dlgOverflow = document.body.style.overflow;
      document.body.style.overflow = 'hidden';

      /* โฟกัสที่ปุ่มยกเลิกก่อนเสมอ ทั้งที่ปุ่มยืนยันเด่นกว่า
         เพราะกด Enter รัว ๆ ไม่ควรลบอะไรไปโดยไม่ตั้งใจ */
      dlg.querySelector('[data-cdlg="no"]').focus();
    });
  }

  function closeDialog(answer) {
    if (!dlg || dlg.hidden) return;
    dlg.hidden = true;
    document.body.style.overflow = dlgOverflow;

    if (dlgOpener && document.body.contains(dlgOpener) && dlgOpener.focus) {
      dlgOpener.focus();
    }
    dlgOpener = null;

    var done = dlgAnswer;
    dlgAnswer = null;
    if (done) done(answer);
  }

  document.addEventListener('click', function (ev) {
    if (!dlg || dlg.hidden) return;
    var hit = ev.target.closest && ev.target.closest('[data-cdlg]');
    if (hit) {
      ev.preventDefault();
      closeDialog(hit.getAttribute('data-cdlg') === 'yes');
      return;
    }
    if (ev.target === dlg) closeDialog(false);   // คลิกพื้นที่มืด = ยกเลิก
  });

  document.addEventListener('keydown', function (ev) {
    if (!dlg || dlg.hidden) return;
    if (ev.key === 'Escape' || ev.key === 'Esc') {
      ev.preventDefault();
      closeDialog(false);
      return;
    }
    if (ev.key === 'Tab') trapTab(dlgBox, ev);
  });

  function post(body) {
    body.append('csrf', csrf);
    return fetch('media.php?ajax=1', { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'เซิร์ฟเวอร์ตอบกลับผิดรูปแบบ' }; }); });
  }

  /* ---------------------------------------------------------- อัปโหลด */

  var zone = document.getElementById('dropzone');
  var pick = document.getElementById('filepick');
  var log = document.getElementById('uploadlog');
  var grid = document.getElementById('mediagrid');

  function note(kind, text) {
    if (!log) return;
    log.hidden = false;
    var row = document.createElement('div');
    row.className = 'unote ' + kind;
    row.textContent = text;
    log.appendChild(row);
    return row;
  }

  function human(bytes) {
    return bytes >= 1048576 ? (bytes / 1048576).toFixed(1) + ' MB' : Math.round(bytes / 1024) + ' KB';
  }

  function upload(file) {
    if (!/^image\/(jpeg|png|webp)$/.test(file.type)) {
      note('err', file.name + ' — ไฟล์นี้ไม่ใช่รูป JPG, PNG หรือ WebP');
      return Promise.resolve();
    }
    var row = note('busy', 'กำลังอัปโหลด ' + file.name + ' (' + human(file.size) + ') …');

    var fd = new FormData();
    fd.append('action', 'upload');
    fd.append('file', file);

    return post(fd).then(function (res) {
      if (!res.ok) {
        row.className = 'unote err';
        row.textContent = file.name + ' — ' + (res.error || 'อัปโหลดไม่สำเร็จ');
        return;
      }
      var d = res.data;
      row.className = 'unote ok';
      row.textContent = d.name + ' — อัปโหลดแล้ว' +
        (d.resized ? ' · ย่อจาก ' + d.origDim + ' เหลือ ' + d.dim + ' อัตโนมัติ' : ' · ' + d.dim);
      addCard(d);
    }).catch(function () {
      row.className = 'unote err';
      row.textContent = file.name + ' — เชื่อมต่อเซิร์ฟเวอร์ไม่ได้';
    });
  }

  /* เพิ่มการ์ดรูปใหม่เข้าไปโดยไม่ต้องโหลดหน้าใหม่ */
  function addCard(d) {
    if (!grid) { location.reload(); return; }
    var empty = document.getElementById('emptymsg');
    if (empty) empty.remove();

    var fig = document.createElement('figure');
    fig.className = 'mcard';
    fig.setAttribute('data-id', d.id);
    fig.innerHTML =
      '<div class="mthumb"><img src="../' + d.thumb + '" alt=""></div>' +
      '<figcaption><strong></strong>' +
      '<span class="meta"></span>' +
      '<span class="freetag">ยังไม่ได้ใช้</span></figcaption>' +
      '<form method="post" class="altform" data-id="' + d.id + '">' +
      '<input type="hidden" name="csrf" value="' + csrf + '">' +
      '<input type="hidden" name="action" value="alt">' +
      '<input type="hidden" name="id" value="' + d.id + '">' +
      '<label>คำบรรยายรูป (ไทย)</label><input type="text" name="alt_th" maxlength="255">' +
      '<label>คำบรรยายรูป (อังกฤษ)</label><input type="text" name="alt_en" maxlength="255">' +
      '<p class="hint">คำบรรยายช่วยให้คนตาบอดที่ใช้โปรแกรมอ่านหน้าจอเข้าใจรูป และช่วยเรื่องอันดับค้นหา</p>' +
      '<div class="mactions"><button type="submit" class="mini">บันทึกคำบรรยาย</button>' +
      '<button type="button" class="mini danger" data-del="' + d.id + '">ลบรูป</button></div></form>';

    // ใส่ข้อความด้วย textContent เพื่อไม่ให้ชื่อไฟล์กลายเป็น HTML
    fig.querySelector('figcaption strong').textContent = d.name;
    fig.querySelector('figcaption strong').title = d.name;
    fig.querySelector('.meta').textContent = d.dim + ' · ' + d.size;

    grid.insertBefore(fig, grid.firstChild);
    bumpCount(1);
  }

  function bumpCount(delta) {
    var el = document.getElementById('mediacount');
    if (!el) return;
    var n = (parseInt(el.textContent, 10) || 0) + delta;
    el.textContent = n + ' รูป';
  }

  function handleFiles(list) {
    var files = Array.prototype.slice.call(list);
    // อัปโหลดทีละไฟล์ ไม่ยิงพร้อมกัน เพื่อไม่ให้โฮสติ้งแบบแชร์รับไม่ไหว
    files.reduce(function (chain, f) {
      return chain.then(function () { return upload(f); });
    }, Promise.resolve());
  }

  if (zone && pick) {
    zone.addEventListener('click', function () { pick.click(); });
    zone.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); pick.click(); }
    });
    pick.addEventListener('change', function () {
      handleFiles(pick.files);
      pick.value = '';
    });

    ['dragenter', 'dragover'].forEach(function (t) {
      zone.addEventListener(t, function (ev) { ev.preventDefault(); zone.classList.add('over'); });
    });
    ['dragleave', 'drop'].forEach(function (t) {
      zone.addEventListener(t, function (ev) { ev.preventDefault(); zone.classList.remove('over'); });
    });
    zone.addEventListener('drop', function (ev) {
      if (ev.dataTransfer && ev.dataTransfer.files) handleFiles(ev.dataTransfer.files);
    });

    // กันลากรูปมาวางผิดที่แล้วเบราว์เซอร์เปิดไฟล์นั้นแทน
    ['dragover', 'drop'].forEach(function (t) {
      document.addEventListener(t, function (ev) {
        if (!zone.contains(ev.target)) ev.preventDefault();
      });
    });
  }

  /* ------------------------------------------------- ลบ / บันทึกคำบรรยาย */

  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest && ev.target.closest('[data-del]');
    if (!btn || btn.disabled) return;
    ev.preventDefault();

    var card = btn.closest('.mcard');
    var name = card ? (card.querySelector('figcaption strong') || {}).textContent : '';

    askConfirm({
      title: 'ลบรูปออกจากคลัง',
      message: 'จะลบรูป "' + name + '" ออกจากคลังถาวร\nการลบย้อนกลับไม่ได้',
      okLabel: 'ลบรูป',
      danger: true,
      opener: btn,
    }).then(function (yes) {
      if (!yes) return;

      var fd = new FormData();
      fd.append('action', 'delete');
      fd.append('id', btn.getAttribute('data-del'));
      btn.disabled = true;

      return post(fd).then(function (res) {
        if (!res.ok) { alert(res.error || 'ลบไม่สำเร็จ'); btn.disabled = false; return; }
        if (card) card.remove();
        bumpCount(-1);
      });
    });
  });

  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (!form.classList || !form.classList.contains('altform')) return;
    ev.preventDefault();

    var fd = new FormData(form);
    var btn = form.querySelector('button[type=submit]');
    var old = btn ? btn.textContent : '';
    if (btn) { btn.disabled = true; btn.textContent = 'กำลังบันทึก…'; }

    post(fd).then(function (res) {
      if (btn) {
        btn.disabled = false;
        btn.textContent = res.ok ? 'บันทึกแล้ว ✓' : 'บันทึกไม่สำเร็จ';
        setTimeout(function () { btn.textContent = old; }, 2000);
      }
      if (!res.ok) alert(res.error || 'บันทึกไม่สำเร็จ');
    });
  });

  /* ------------------------------------------- เตือนเมื่อยังไม่ได้บันทึก
   *
   * ถ้า JavaScript ถูกปิด ทุกอย่างยังใช้ได้ตามปกติ แค่ไม่มีคำเตือนเท่านั้น
   * ฟอร์มเป็นฟอร์มธรรมดา ปุ่มเป็นปุ่ม submit จริง
   */

  var form = document.getElementById('editform');
  var state = document.getElementById('savestate');
  var dirty = false;

  /* ตั้งเป็น true เมื่อ "ตกลงแล้วว่ากำลังจะออกจากหน้านี้" ไม่ว่าจะเพราะกดบันทึก
     หรือเพราะผู้ใช้ตอบยืนยันกับคำเตือนไปแล้ว
     ถ้าไม่มีตัวนี้ ผู้ใช้จะโดนถามสองรอบ — รอบแรกจากตัวจับคลิกลิงก์
     รอบสองจาก beforeunload ของเบราว์เซอร์ */
  var navigating = false;

  function markDirty() {
    if (dirty) return;
    dirty = true;
    if (state) {
      state.textContent = 'ยังไม่ได้บันทึก';
      state.classList.add('unsaved');
    }
    document.body.classList.add('has-unsaved');
  }

  if (form) {
    // input ครอบคลุมทั้งพิมพ์ ติ๊ก และเลือก dropdown
    form.addEventListener('input', markDirty);
    form.addEventListener('change', markDirty);
  }

  /* ดักที่ระดับเอกสาร ไม่ใช่ที่ฟอร์มแก้เนื้อหาอย่างเดียว
     เพราะหน้าจัดการผู้ใช้ก็มีปุ่มที่ต้องถามก่อน (ลบบัญชี, เตะออก, ตั้งรหัสใหม่)
     และปุ่มพวกนั้นอยู่คนละฟอร์มกัน ฟอร์มละปุ่ม */
  /** ส่งฟอร์มซ้ำหลังผู้ใช้ยืนยันแล้ว โดยคงค่าของปุ่มที่กดไว้เหมือนเดิม */
  function resubmit(theForm, btn) {
    if (btn && typeof theForm.requestSubmit === 'function') {
      theForm.requestSubmit(btn);
      return;
    }
    /* เบราว์เซอร์เก่าที่ไม่มี requestSubmit — ยัดค่าปุ่มเป็นช่องซ่อนแทน
       ไม่งั้นฝั่งเซิร์ฟเวอร์จะไม่รู้ว่ากดปุ่มไหนมา */
    if (btn && btn.name) {
      var h = document.createElement('input');
      h.type = 'hidden';
      h.name = btn.name;
      h.value = btn.value;
      theForm.appendChild(h);
    }
    theForm.submit();
  }

  document.addEventListener('submit', function (ev) {
    if (ev.defaultPrevented) return;

    var btn = ev.submitter;
    var theForm = ev.target;

    if (btn && btn.hasAttribute('data-confirm') && !btn.fmkConfirmed) {
      ev.preventDefault();
      askConfirm({
        title: btn.getAttribute('data-confirm-title') || 'ยืนยันการทำรายการ',
        message: btn.getAttribute('data-confirm'),
        okLabel: btn.getAttribute('data-confirm-ok') || 'ยืนยัน',
        danger: btn.classList.contains('danger'),
        opener: btn,
      }).then(function (yes) {
        if (!yes) return;
        btn.fmkConfirmed = true;              // รอบหน้าปล่อยผ่าน ไม่ถามซ้ำ
        if (form && theForm === form) navigating = true;
        resubmit(theForm, btn);
      });
      return;
    }
    if (btn) btn.fmkConfirmed = false;

    // เฉพาะฟอร์มแก้เนื้อหาเท่านั้นที่มีสถานะ "ยังไม่ได้บันทึก" ให้ต้องปิดเสียง
    if (form && theForm === form) {
      navigating = true;
    }
  });

  window.addEventListener('beforeunload', function (ev) {
    if (!dirty || navigating) return;
    ev.preventDefault();
    ev.returnValue = '';   // เบราว์เซอร์จะแสดงข้อความมาตรฐานของตัวเอง
    return '';
  });

  /* กดปุ่มย้อนกลับแล้วเบราว์เซอร์คืนหน้าเดิมจากแคช — ยังแก้ค้างอยู่เหมือนเดิม
     ต้องล้างสถานะ "กำลังจะออก" ไม่งั้นครั้งต่อไปจะออกได้เงียบ ๆ โดยไม่เตือน */
  window.addEventListener('pageshow', function (ev) {
    if (ev.persisted) navigating = false;
  });

  /* กดลิงก์ไปหน้าอื่นทั้งที่ยังไม่บันทึก — ถามก่อน
     (beforeunload ไม่ทำงานกับลิงก์ในบางเบราว์เซอร์เมื่อผู้ใช้ยังไม่ได้แตะหน้าเว็บ) */
  document.addEventListener('click', function (ev) {
    if (!dirty || navigating || ev.defaultPrevented) return;

    /* คลิกกลางปุ่ม หรือกด Ctrl/Cmd/Shift ค้าง = เปิดแท็บ/หน้าต่างใหม่
       หน้านี้ไม่ได้ไปไหน จึงไม่ต้องเตือน และสถานะ "ยังไม่บันทึก" ต้องคงอยู่ */
    if (ev.button !== 0 || ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey) return;

    var a = ev.target.closest && ev.target.closest('a[href]');
    if (!a) return;

    // ลิงก์ที่เปิดแท็บใหม่ ก็ไม่ได้พาออกจากหน้านี้เช่นกัน
    if (a.target && a.target !== '' && a.target !== '_self') return;
    if (a.hasAttribute('download')) return;

    var href = a.getAttribute('href') || '';
    if (href === '' || href.charAt(0) === '#') return;
    if (/^(javascript|mailto|tel):/i.test(href)) return;

    if (!confirm('ยังมีการแก้ไขที่ไม่ได้บันทึก\nถ้าออกจากหน้านี้ การแก้ไขจะหายไป\n\nต้องการออกจากหน้านี้หรือไม่?')) {
      ev.preventDefault();   // อยู่หน้าเดิม ข้อความที่พิมพ์ไว้ยังอยู่ครบ
      return;
    }
    navigating = true;       // ตอบตกลงแล้ว beforeunload จะได้ไม่ถามซ้ำอีกรอบ
  });

  /* ------------------------------------------------------- ตัวเลือกรูป */

  var picker = document.getElementById('imgpicker');
  var pickerBox = picker ? picker.querySelector('.pickerbox') : null;

  /* ปุ่มที่เป็นคนเปิดหน้าต่างนี้ — ปิดแล้วต้องคืน focus กลับไปให้ ไม่งั้นคนที่ใช้
     คีย์บอร์ดหรือโปรแกรมอ่านหน้าจอจะถูกโยนกลับไปต้นหน้าแล้วหลงทาง */
  var pickerOpener = null;
  var bodyOverflow = '';

  function openPicker(opener) {
    if (!picker || !picker.hidden) return;
    pickerOpener = opener;
    picker.setAttribute('data-target', opener.getAttribute('data-pick-for'));
    picker.hidden = false;

    bodyOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';   // กันหน้าเว็บด้านหลังเลื่อนตาม

    /* มีรูปก็ไปที่รูปแรก ยังไม่มีรูปก็ไปที่ปุ่มปิด จะได้ไม่ค้างอยู่นอกหน้าต่าง */
    var first = picker.querySelector('.pickitem') ||
                picker.querySelector('[data-close-picker]') ||
                pickerBox;
    if (first && first.focus) first.focus();
  }

  function closePicker() {
    if (!picker || picker.hidden) return;
    picker.hidden = true;
    picker.removeAttribute('data-target');
    document.body.style.overflow = bodyOverflow;

    if (pickerOpener && document.body.contains(pickerOpener) && pickerOpener.focus) {
      pickerOpener.focus();
    }
    pickerOpener = null;
  }

  document.addEventListener('click', function (ev) {
    var open = ev.target.closest && ev.target.closest('[data-pick-for]');
    if (open && picker) {
      ev.preventDefault();
      openPicker(open);
      return;
    }

    if (ev.target.closest && ev.target.closest('[data-close-picker]')) {
      closePicker();
      return;
    }

    var choose = ev.target.closest && ev.target.closest('.pickitem');
    if (choose && picker && !picker.hidden) {
      ev.preventDefault();
      applyPick(choose.getAttribute('data-url'), choose.getAttribute('data-alt-th') || '',
                choose.getAttribute('data-alt-en') || '');
      return;
    }

    // คลิกพื้นที่มืดนอกหน้าต่าง = ปิด เป็นพฤติกรรมที่คนคุ้นอยู่แล้ว
    if (picker && !picker.hidden && ev.target === picker) {
      closePicker();
      return;
    }

    var clear = ev.target.closest && ev.target.closest('[data-clear-img]');
    if (clear) {
      ev.preventDefault();
      var name = clear.getAttribute('data-clear-img');
      setSlot(name, '', null, null);
    }
  });

  /* Escape เพื่อปิด และขัง Tab ไว้ในหน้าต่าง
     ถ้าไม่ขัง กด Tab ไปเรื่อย ๆ จะหลุดไปอยู่กับฟอร์มข้างหลังที่มองไม่เห็น */
  document.addEventListener('keydown', function (ev) {
    if (!picker || picker.hidden) return;

    if (ev.key === 'Escape' || ev.key === 'Esc') {
      ev.preventDefault();
      closePicker();
      return;
    }
    if (ev.key !== 'Tab' || !pickerBox) return;
    trapTab(pickerBox, ev);
  });

  function applyPick(url, altTh, altEn) {
    var target = picker.getAttribute('data-target');
    setSlot(target, url, altTh, altEn);
    closePicker();
  }

  /* เขียนค่าลงช่องซ่อน แล้วอัปเดตภาพตัวอย่างที่ผู้ใช้เห็น */
  function setSlot(fieldName, url, altTh, altEn) {
    var slot = document.querySelector('.imgslot[data-field="' + fieldName + '"]');
    if (!slot) return;

    slot.querySelectorAll('input.imgval').forEach(function (inp) { inp.value = url; });

    // เติมคำบรรยายให้อัตโนมัติถ้าผู้ใช้ยังไม่เคยกรอกเอง
    if (url && altTh !== null) {
      slot.querySelectorAll('input.imgalt').forEach(function (inp) {
        if (inp.value.trim() !== '') return;
        inp.value = inp.getAttribute('data-lang') === 'th' ? altTh : altEn;
      });
    }

    var prev = slot.querySelector('.imgpreview');
    if (prev) {
      if (url) {
        prev.innerHTML = '';
        var im = document.createElement('img');
        im.src = '../' + url;
        im.alt = '';
        prev.appendChild(im);
        prev.classList.remove('none');
      } else {
        prev.textContent = 'ยังไม่มีรูป';
        prev.classList.add('none');
      }
    }
    var clearBtn = slot.querySelector('[data-clear-img]');
    if (clearBtn) clearBtn.hidden = !url;

    // ปุ่มต้องบอกสิ่งที่จะเกิดขึ้นจริง ไม่ใช่ค้างเป็น "เลือกรูปจากคลัง" ทั้งที่มีรูปแล้ว
    var pickBtn = slot.querySelector('[data-pick-label]');
    if (pickBtn) pickBtn.textContent = url ? 'เปลี่ยนรูป' : 'เลือกรูปจากคลัง';

    // เปลี่ยนรูปก็ถือเป็นการแก้ไขที่ยังไม่ได้บันทึก
    markDirty();
  }
})();
