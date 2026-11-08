/* ---------- one document, in the drawer ---------- */
async function openDetail(id){
  let doc;
  try{ doc = (await api("GET", "/api/documents/" + id)).document; }
  catch(e){ if(e.status !== 401) toast(errorWords(e), "warn"); return; }

  const current = doc.versions[0];
  $("#d-title").textContent = doc.title;
  // (replaceChildren turns a null into the text "null", so only real elements go in)
  $("#d-sub").replaceChildren(...[
    h("span", {class: "tag"}, doc.code),
    h("span", {class: "pill p-mute"}, "Ver " + doc.rev),
    doc.type ? h("span", {}, doc.type) : null,
  ].filter(Boolean));

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
    section("Files in the current version (Ver " + doc.rev + ")", fileList(current.files),
      h("button", {class: "btn sm", type: "button", onclick: () => openNewVersion(doc)}, icon("upload"), "Upload new version")),
    section("Versions", timeline(doc.versions, current.id)));

  openDrawer();
  $("#d-body").scrollTop = 0;
}

function section(title, content, action){
  return h("div", {class: "sec"}, h("h3", {}, title), content, action);
}

// "Last viewed: 8 Nov 2026, 14:30 by Ana Reyes" / "Last downloaded: never"
function accessLine(label, at, by){
  return label + ": " + (at ? fmtStamp(at) + (by ? " by " + by : "") : "never");
}
// the small text under each file, by file id, so pressing View or Download can bring it up to date at once
const accessText = new Map();
function accessLines(file){
  return [accessLine("Last viewed", file.lastViewedAt, file.lastViewedBy),
          accessLine("Last downloaded", file.lastDownloadedAt, file.lastDownloadedBy)].join("\n");
}
// a file was just viewed or downloaded by the signed-in person
function noteAccess(file, kind){
  file["last" + (kind === "viewed" ? "Viewed" : "Downloaded") + "At"] = new Date().toISOString();
  file["last" + (kind === "viewed" ? "Viewed" : "Downloaded") + "By"] = currentUser ? currentUser.name || currentUser.username : "";
  const line = accessText.get(file.id);
  if(line && line.isConnected) line.textContent = accessLines(file);
  loadDocuments();   // the table's last viewed / downloaded columns
}

function fileList(files){
  return h("div", {class: "files"}, files.map(file => h("div", {class: "file-row"},
    h("span", {class: "fi"}, icon("docs")),
    h("div", {class: "fb"}, h("div", {class: "fn"}, file.name), h("div", {class: "fs"}, fmtSize(file.size)),
      (() => { const line = h("div", {class: "fs access"}, accessLines(file)); accessText.set(file.id, line); return line; })()),
    h("div", {class: "file-acts"},
      previewKind(file.name) ? h("button", {class: "btn sm", type: "button", onclick: () => openViewer(file)}, icon("eye"), "View") : null,
      h("button", {class: "btn sm", type: "button", onclick: event => downloadClicked(event.currentTarget, file)}, icon("download"), "Download")))));
}

async function downloadClicked(button, file){
  button.disabled = true;
  try{ await downloadFile(file); }
  finally{ button.disabled = false; }
}

// every version, newest first; an older version lists its files here with View and Download (the current one is above)
function timeline(versions, currentId){
  return h("div", {class: "tl"}, versions.map(v => h("div", {class: "tl-item"},
    h("span", {class: "tl-dot"}),
    h("div", {class: "tl-top"}, h("strong", {}, "Ver " + v.rev), h("span", {class: "tl-when"}, fmtStamp(v.createdAt))),
    h("div", {class: "tl-sub"}, (v.rev === 0 ? "Registered by " : "Uploaded by ") + (v.createdBy || "unknown") + ", " + plural(v.files.length, "file")),
    v.changeNote ? h("div", {class: "tl-note"}, v.changeNote) : null,
    v.id === currentId ? h("div", {class: "tl-sub"}, "Current version, files above") : h("div", {class: "tl-files"}, fileList(v.files)))));
}
