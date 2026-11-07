/* ---------- one document, in the drawer ---------- */
async function openDetail(id){
  let doc;
  try{ doc = (await api("GET", "/api/documents/" + id)).document; }
  catch(e){ if(e.status !== 401) toast(errorWords(e), "warn"); return; }

  const current = doc.versions[0];
  $("#d-title").textContent = doc.title;
  $("#d-sub").replaceChildren(
    h("span", {class: "tag"}, doc.code),
    h("span", {class: "pill p-mute"}, "Rev " + doc.rev),
    doc.type ? h("span", {}, doc.type) : null);

  // label / value pairs; a pair with no value is left out
  const kv = rows => h("dl", {class: "kv"}, rows.filter(r => r[1]).flatMap(r => [h("dt", {}, r[0]), h("dd", {class: r[2] || ""}, r[1])]));
  const details = kv([
    ["Department", doc.department],
    ["Issued on", fmtDate(doc.issuedOn)],
    ["Expires on", doc.expiresOn ? expiryContent(doc) : ""],
    ["Physical location", doc.location],
    ["Description", doc.description, "pre"],
    ["Added by", doc.createdBy ? doc.createdBy + ", " + fmtStamp(doc.createdAt) : ""],
  ]);

  $("#d-body").replaceChildren(
    section("Details", details.children.length ? details : h("p", {class: "empty-mini"}, "No details were entered.")),
    section("Files in the current version (Rev " + doc.rev + ")", fileList(current.files)),
    section("Versions", timeline(doc.versions)));

  openDrawer();
  $("#d-body").scrollTop = 0;
}

function section(title, content){
  return h("div", {class: "sec"}, h("h3", {}, title), content);
}

function fileList(files){
  return h("div", {class: "files"}, files.map(file => h("div", {class: "file-row"},
    h("span", {class: "fi"}, icon("docs")),
    h("div", {class: "fb"}, h("div", {class: "fn"}, file.name), h("div", {class: "fs"}, fmtSize(file.size))),
    h("button", {class: "btn sm", type: "button", onclick: event => downloadClicked(event.currentTarget, file)}, icon("download"), "Download"))));
}

async function downloadClicked(button, file){
  button.disabled = true;
  try{ await apiDownload("/api/files/" + file.id, file.name); }
  catch(e){ if(e.status !== 401) toast(e.code === "file_missing" ? "That file is missing from the server's storage." : errorWords(e), "warn"); }
  finally{ button.disabled = false; }
}

function timeline(versions){
  return h("div", {class: "tl"}, versions.map(v => h("div", {class: "tl-item"},
    h("span", {class: "tl-dot"}),
    h("div", {class: "tl-top"}, h("strong", {}, "Rev " + v.rev), h("span", {class: "tl-when"}, fmtStamp(v.createdAt))),
    h("div", {class: "tl-sub"}, (v.rev === 0 ? "Registered by " : "Uploaded by ") + (v.createdBy || "unknown") + ", " + plural(v.files.length, "file")),
    v.changeNote ? h("div", {class: "tl-note"}, v.changeNote) : null)));
}
