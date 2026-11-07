/* ---------- the file viewer ---------- */
// Shows what is inside a file without leaving the app: a PDF in the browser's own PDF viewer, a picture, plain text,
// or a CSV as a table. Word, Excel and PowerPoint cannot be drawn by a browser, so they only have Download.
const PREVIEW_KINDS = {pdf: "pdf", png: "image", jpg: "image", jpeg: "image", txt: "text", csv: "csv"};
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

    if(kind === "pdf" || kind === "image"){
      address = URL.createObjectURL(blob);
      stage.replaceChildren(kind === "pdf"
        ? h("iframe", {class: "viewer-frame", src: address, title: file.name})
        : h("img", {class: "viewer-img", src: address, alt: file.name}));
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

async function downloadFile(file){
  try{ await apiDownload("/api/files/" + file.id, file.name); }
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
  const rows = parseCsv(text.replace(/^﻿/, ""));
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
