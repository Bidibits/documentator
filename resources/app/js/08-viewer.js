/* ---------- the file viewer ---------- */
// Shows what is inside a file without leaving the app: a PDF in the browser's own PDF viewer, a picture, plain text,
// a CSV as a table, a Word (.docx) file as text and a spreadsheet (.xlsx) as a table. The older .doc/.xls and PowerPoint
// cannot be drawn, so they only have Download. The Word and Excel readers are vendored (resources/app/vendor) and are
// only loaded the first time one of those files is opened.
const PREVIEW_KINDS = {pdf: "pdf", png: "image", jpg: "image", jpeg: "image", txt: "text", csv: "csv", docx: "docx", xlsx: "xlsx"};
const VENDOR = {docx: "mammoth.browser.min.js", xlsx: "read-excel-file.min.js"};
const TEXT_LIMIT = 1024 * 1024;   // bytes of text shown; the rest is only in the download
const CSV_ROW_LIMIT = 1000;
let viewerToken = 0;

function previewKind(name){
  const m = /\.([^.]+)$/.exec(name || "");
  return m ? PREVIEW_KINDS[m[1].toLowerCase()] || null : null;
}

async function openViewer(file){
  const kind = previewKind(file.name);
  if(!kind) return;

  const token = ++viewerToken;
  let address = null;
  const stage = h("div", {class: "viewer-stage"}, h("div", {class: "viewer-note"}, "Opening " + file.name + "..."));
  openModal(file.name, stage, () => downloadFile(file), "Download", {
    cls: "viewer", cancelLabel: "Close",
    onClose: () => { viewerToken++; if(address) URL.revokeObjectURL(address); },
  });

  try{
    const blob = await apiBlob("/api/files/" + file.id + "?view=1");
    if(token !== viewerToken) return;   // closed, or another file opened, while this one was coming
    noteAccess(file, "viewed");

    if(kind === "pdf" || kind === "image"){
      address = URL.createObjectURL(blob);
      stage.replaceChildren(kind === "pdf"
        ? h("iframe", {class: "viewer-frame", src: address, title: file.name})
        : h("img", {class: "viewer-img", src: address, alt: file.name}));
      return;
    }

    if(kind === "docx" || kind === "xlsx"){
      await loadVendor(kind);
      if(token !== viewerToken) return;
      const shown = kind === "docx" ? await docxView(blob) : await xlsxView(blob, () => token !== viewerToken);
      if(token !== viewerToken) return;
      stage.replaceChildren(shown);
      return;
    }

    const text = await blob.slice(0, TEXT_LIMIT).text();
    if(token !== viewerToken) return;
    const cut = blob.size > TEXT_LIMIT;
    stage.replaceChildren(...[
      kind === "csv" ? csvTable(text) : h("pre", {class: "viewer-text"}, text),
      cut ? h("div", {class: "viewer-note"}, "Only the first " + fmtSize(TEXT_LIMIT) + " is shown. Download the file for all of it.") : null,
    ].filter(Boolean));
  }catch(e){
    if(token !== viewerToken || e.status === 401) return;
    stage.replaceChildren(h("div", {class: "viewer-note"},
      e.code === "file_missing" ? "That file is missing from the server's storage." : errorWords(e)));
  }
}

// Loads a vendored reader once, as a script from this site (the page allows no other).
const vendorLoads = {};
function loadVendor(kind){
  if(!vendorLoads[kind]) vendorLoads[kind] = new Promise((resolve, reject) => {
    const script = document.createElement("script");
    script.src = "/build/documentator/vendor/" + VENDOR[kind];
    script.onload = resolve;
    script.onerror = () => { delete vendorLoads[kind]; reject(new Error("The reader for this kind of file did not load.")); };
    document.head.append(script);
  });
  return vendorLoads[kind];
}

// A Word file: mammoth turns it into HTML, which is never put into the page as markup. It is read as a document, and
// only the plain tags below are copied across (no links, scripts, styles or attributes), pictures only as data.
const DOCX_TAGS = new Set(["P","H1","H2","H3","H4","H5","H6","STRONG","B","EM","I","U","S","SUB","SUP","BR","UL","OL","LI",
  "TABLE","THEAD","TBODY","TR","TD","TH","BLOCKQUOTE","IMG","A","SPAN"]);
function cleanDocx(node){
  if(node.nodeType === 3) return document.createTextNode(node.nodeValue);
  if(node.nodeType !== 1 || !DOCX_TAGS.has(node.tagName)) return null;
  const tag = node.tagName === "A" ? "span" : node.tagName.toLowerCase();
  const el = document.createElement(tag);
  if(tag === "img"){
    const src = node.getAttribute("src") || "";
    if(!/^data:image\/(png|jpe?g|gif);base64,[A-Za-z0-9+\/=]+$/.test(src)) return null;
    el.setAttribute("src", src);
    el.setAttribute("alt", node.getAttribute("alt") || "");
    return el;
  }
  for(const attr of ["colspan", "rowspan"]){
    const n = parseInt(node.getAttribute(attr), 10);
    if((tag === "td" || tag === "th") && n > 1 && n < 100) el.setAttribute(attr, n);
  }
  for(const child of node.childNodes){ const c = cleanDocx(child); if(c) el.append(c); }
  return el;
}
async function docxView(blob){
  const result = await window.mammoth.convertToHtml({arrayBuffer: await blob.arrayBuffer()});
  const parsed = new DOMParser().parseFromString(result.value, "text/html");
  const page = h("div", {class: "viewer-doc"});
  for(const child of parsed.body.childNodes){ const c = cleanDocx(child); if(c) page.append(c); }
  if(!page.hasChildNodes()) page.append(h("div", {class: "viewer-note"}, "This document has no text to show."));
  return h("div", {class: "viewer-docwrap"}, page,
    h("div", {class: "viewer-note"}, "A plain preview: fonts, headers, footers and page layout are not shown. Download the file to see it as written."));
}

// A spreadsheet: sheet tabs along the top, the chosen sheet as a table (first 1000 rows).
async function xlsxView(blob, closed){
  const sheets = await window.readXlsxFile(blob, {getSheets: true});
  const holder = h("div", {class: "viewer-sheet-body"});
  const tabs = h("div", {class: "viewer-tabs"});
  const note = h("div", {class: "viewer-note"}, "A plain preview: formulas show their last saved value, and formatting and charts are not shown.");
  async function show(name, button){
    for(const b of tabs.children) b.classList.toggle("on", b === button);
    holder.replaceChildren(h("div", {class: "viewer-note"}, "Opening..."));
    try{
      const rows = await window.readXlsxFile(blob, {sheet: name});
      if(closed()) return;
      holder.replaceChildren(rowsTable(rows.map(r => r.map(cellText))));
    }catch(e){ if(!closed()) holder.replaceChildren(h("div", {class: "viewer-note"}, "That sheet could not be read.")); }
  }
  sheets.forEach((s, i) => {
    const button = h("button", {type: "button", class: "viewer-tab"}, s.name);
    button.addEventListener("click", () => show(s.name, button));
    tabs.append(button);
    if(i === 0) show(s.name, button);
  });
  return h("div", {class: "viewer-sheet"}, sheets.length > 1 ? tabs : null, holder, note);
}
function cellText(v){
  if(v == null) return "";
  if(v instanceof Date) return isNaN(v) ? "" : v.toISOString().slice(0, 10);
  return String(v);
}

async function downloadFile(file){
  try{ await apiDownload("/api/files/" + file.id, file.name); noteAccess(file, "downloaded"); }
  catch(e){ if(e.status !== 401) toast(e.code === "file_missing" ? "That file is missing from the server's storage." : errorWords(e), "warn"); }
}

// A CSV as a table: quotes, commas and line breaks inside quotes are honoured. The first row is the header.
function parseCsv(text){
  const rows = [];
  let row = [], field = "", quoted = false;
  for(let i = 0; i < text.length; i++){
    const c = text[i];
    if(quoted){
      if(c === '"' && text[i + 1] === '"'){ field += '"'; i++; }
      else if(c === '"') quoted = false;
      else field += c;
    }else if(c === '"' && field === "") quoted = true;
    else if(c === ","){ row.push(field); field = ""; }
    else if(c === "\n" || c === "\r"){
      if(c === "\r" && text[i + 1] === "\n") i++;
      row.push(field); field = "";
      rows.push(row); row = [];
      if(rows.length > CSV_ROW_LIMIT) return rows;
    }else field += c;
  }
  if(field !== "" || row.length) { row.push(field); rows.push(row); }
  return rows;
}

function csvTable(text){
  return rowsTable(parseCsv(text.replace(/^﻿/, "")));
}

// rows (arrays of text) as a table; the first row is the header
function rowsTable(rows){
  if(rows.length === 0) return h("div", {class: "viewer-note"}, "This file is empty.");
  const more = rows.length > CSV_ROW_LIMIT;
  const body = rows.slice(1, CSV_ROW_LIMIT);
  const width = Math.max(...rows.slice(0, CSV_ROW_LIMIT).map(r => r.length));
  const cells = (r, tag) => Array.from({length: width}, (_, i) => h(tag, {}, r[i] || ""));
  return h("div", {class: "viewer-csv"},
    h("table", {class: "rt"},
      h("thead", {}, h("tr", {}, cells(rows[0], "th"))),
      h("tbody", {}, body.map(r => h("tr", {}, cells(r, "td"))))),
    more ? h("div", {class: "viewer-note"}, "Only the first " + CSV_ROW_LIMIT + " rows are shown. Download the file for all of it.") : null);
}
