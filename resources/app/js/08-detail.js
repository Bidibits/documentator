/* ---------- one document, in the drawer ---------- */
async function openDetail(id){
  let doc;
  try{ doc = (await api("GET", "/api/documents/" + id)).document; }
  catch(e){ if(e.status !== 401) toast(errorWords(e), "warn"); return; }

  const current = doc.versions[0];
  const canDownload = !doc.can || doc.can.download;   // the server decides; this only hides the buttons
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
    ["Folder", doc.folder],
    ["Issued on", fmtDate(doc.issuedOn)],
    ["Expires on", doc.expiresOn ? expiryContent(doc) : ""],
    ["Reminder", doc.expiresOn ? "Tell me " + plural(doc.leadDays, "day") + " before" + (doc.remindDays == null ? " (the default)" : "") : ""],
    ["Physical location", doc.location],
    ["Description", doc.description, "pre"],
    ["Added by", doc.createdBy ? doc.createdBy + ", " + fmtStamp(doc.createdAt) : ""],
  ]);

  $("#d-body").replaceChildren(
    section("Details", details.children.length ? details : h("p", {class: "empty-mini"}, "No details were entered."),
      doc.can && doc.can.update ? h("button", {class: "btn sm", type: "button", onclick: () => openEditDetails(doc)}, "Edit details") : null),
    section("Files in the current version (Ver " + doc.rev + ")", fileList(current.files, canDownload),
      doc.can && doc.can.update ? h("div", {class: "sec-acts"},
        h("button", {class: "btn sm", type: "button", onclick: () => openNewVersion(doc)}, icon("upload"), "Upload new version"),
        doc.expiresOn ? h("button", {class: "btn sm", type: "button", onclick: () => openNewVersion(doc, true)}, icon("upload"), "Renew") : null) : null),
    section("Versions", timeline(doc.versions, current.id, canDownload)),
    doc.can && doc.can.delete ? section("Delete", h("p", {class: "hint"}, "Removes this document, every version and every file for good. Audits keeps a record of what was deleted, but the files cannot come back."),
      h("button", {class: "btn sm danger", type: "button", onclick: () => confirmDeleteDocument(doc)}, "Delete document")) : null);

  openDrawer();
  $("#d-body").scrollTop = 0;
}

// Delete for good: the person must type the document's code, and the server checks it again
function confirmDeleteDocument(doc){
  const {box, fail, clear} = formError();
  const typed = h("input", {id: "fld-confirm", name: "confirm", type: "text", autocomplete: "off", spellcheck: "false", placeholder: doc.code});
  const body = h("form", {novalidate: true, onsubmit: e => e.preventDefault()}, box,
    h("p", {}, "This deletes " + doc.code + " (" + doc.title + ") with its " + plural(doc.versions.length, "version") + " and all their files. It cannot be undone."),
    formField("confirm", "Type " + doc.code + " to confirm", typed));
  openModal("Delete " + doc.code + "?", body, async () => {
    clear();
    if(typed.value.trim().toLowerCase() !== doc.code.toLowerCase()) return fail("Type the code " + doc.code + " exactly, to be sure.", typed);
    try{
      await api("DELETE", "/api/documents/" + doc.id, {confirm: typed.value.trim()});
      closeModal();
      closeDrawer();
      toast("Deleted " + doc.code);
      await loadDocuments();
    }catch(e){
      if(e.status === 401) return;
      if(e.code === "forbidden") return fail("Your role does not allow deleting this document.");
      if(e.code === "invalid") return fail("Type the code " + doc.code + " exactly, to be sure.", typed);
      fail(errorWords(e));
    }
  }, "Delete for good");
  $("#m-save").classList.add("danger-solid");
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

function fileList(files, canDownload = true){
  return h("div", {class: "files"}, files.map(file => h("div", {class: "file-row"},
    h("span", {class: "fi"}, icon("docs")),
    h("div", {class: "fb"}, h("div", {class: "fn"}, file.name), h("div", {class: "fs"}, fmtSize(file.size)),
      (() => { const line = h("div", {class: "fs access"}, accessLines(file)); accessText.set(file.id, line); return line; })()),
    canDownload ? h("div", {class: "file-acts"},
      previewKind(file.name) ? h("button", {class: "btn sm", type: "button", onclick: () => openViewer(file)}, icon("eye"), "View") : null,
      h("button", {class: "btn sm", type: "button", onclick: event => downloadClicked(event.currentTarget, file)}, icon("download"), "Download")) : h("div", {class: "file-acts muted-cell", title: "Your role may see this document but not open its files"}, "No access to the file"))));
}

async function downloadClicked(button, file){
  button.disabled = true;
  try{ await downloadFile(file); }
  finally{ button.disabled = false; }
}

// "Valid 1 Jan 2025 to 31 Dec 2025", "Issued 1 Jan 2025", "Expires 31 Dec 2025"
function validity(v){
  if(v.issuedOn && v.expiresOn) return "Valid " + fmtDate(v.issuedOn) + " to " + fmtDate(v.expiresOn);
  return v.issuedOn ? "Issued " + fmtDate(v.issuedOn) : "Expires " + fmtDate(v.expiresOn);
}

// every version, newest first; an older version lists its files here with View and Download (the current one is above)
function timeline(versions, currentId, canDownload){
  return h("div", {class: "tl"}, versions.map(v => h("div", {class: "tl-item"},
    h("span", {class: "tl-dot"}),
    h("div", {class: "tl-top"}, h("strong", {}, "Ver " + v.rev),
      v.archived ? h("span", {class: "pill p-accent"}, icon("archive"), "Archived") : null,
      h("span", {class: "tl-when"}, fmtStamp(v.createdAt))),
    h("div", {class: "tl-sub"}, (v.rev === 0 ? "Registered by " : "Uploaded by ") + (v.createdBy || "unknown") + ", " + plural(v.files.length, "file")),
    v.changeNote ? h("div", {class: "tl-note"}, v.changeNote) : null,
    v.issuedOn || v.expiresOn ? h("div", {class: "tl-sub"}, validity(v)) : null,
    v.archived ? h("div", {class: "tl-sub"}, "Archived " + fmtStamp(v.archivedAt) + (v.archivedBy ? " by " + v.archivedBy : "") + ", packed into " + fmtSize(v.archiveSize) + ". Its files open and download more slowly.") : null,
    v.id === currentId ? h("div", {class: "tl-sub"}, "Current version, files above") : h("div", {class: "tl-files"}, fileList(v.files, canDownload)))));
}
