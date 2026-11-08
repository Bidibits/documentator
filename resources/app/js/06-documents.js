/* ---------- the documents list: folders, grid or list, pages ---------- */
// Like a drive: documents sit in folders, folders in folders. Browsing shows the folders and documents of one folder; typing
// a search or picking an expiry filter looks through every folder at once. The server does the searching and the paging
// (25 documents to a page); the folders (a small list) are loaded whole.
const docs = {all: [], departments: [], canRegisterNone: false, editDepartments: [], canEditNone: false, types: [],
              query: "", status: "", loaded: false, page: 1, pages: 1, total: 0, per: 25,
              folders: [], rootDocuments: 0, folder: null, view: "list"};
try{ if(localStorage.getItem("documentator.docview.v1") === "grid") docs.view = "grid"; }catch(e){}

// offered in the Type box besides the types already in use (a plain list for now; an admin-kept list can come later)
const DEFAULT_TYPES = ["Contract", "MOA", "Permit / License", "Form", "Template", "Policy", "Memorandum",
                       "Board resolution", "Secretary's certificate", "Certificate", "Letter", "Report", "Other"];

function fillSuggestionLists(){
  const types = [...new Set([...DEFAULT_TYPES, ...docs.types])];
  $("#dl-types").replaceChildren(...types.map(t => h("option", {value: t})));
}

/* ----- the folder tree ----- */
const folderOf = id => docs.folders.find(f => f.id === id) || null;
const childFolders = parent => docs.folders.filter(f => f.parentId === parent);
// the folders from the top down to this one
function folderTrail(id){
  const trail = [];
  for(let f = folderOf(id), n = 0; f && n < 20; f = folderOf(f.parentId), n++) trail.unshift(f);
  return trail;
}
// every folder in order, with its depth, for the Folder boxes ("Contracts", "  2026", "    Leases")
function folderChoices(){
  const out = [];
  const walk = (parent, depth) => { for(const f of childFolders(parent)){ out.push({id: f.id, depth, name: f.name, path: f.path}); walk(f.id, depth + 1); } };
  walk(null, 0);
  return out;
}
// a Folder box: "Top level" then every folder, indented; value is the folder id as text, "" for the top level
function folderSelect(id, value){
  const select = h("select", {id, name: "folder_id"},
    h("option", {value: ""}, "Top level (no folder)"),
    folderChoices().map(f => h("option", {value: String(f.id)}, "  ".repeat(f.depth) + f.name)));
  select.value = value ? String(value) : "";
  return select;
}

async function loadFolders(){
  const data = await api("GET", "/api/folders");
  docs.folders = data.folders || [];
  docs.rootDocuments = data.rootDocuments || 0;
  if(docs.folder !== null && !folderOf(docs.folder)) docs.folder = null;   // it was removed
}

const searching = () => docs.query.trim() !== "" || docs.status !== "";
let docsToken = 0;
async function loadDocuments(page){
  const token = ++docsToken;
  if(page) docs.page = page;
  const params = new URLSearchParams({page: String(docs.page)});
  if(searching()){
    if(docs.query.trim()) params.set("q", docs.query.trim());
    if(docs.status) params.set("status", docs.status);
  }else params.set("folder", docs.folder === null ? "root" : String(docs.folder));
  try{
    const [data] = await Promise.all([api("GET", "/api/documents?" + params.toString()), loadFolders()]);
    if(token !== docsToken) return;   // a newer request is on its way
    docs.all = data.documents || [];
    docs.page = data.page; docs.pages = data.pages; docs.total = data.total; docs.per = data.per;
    docs.departments = data.departments || [];
    docs.canRegisterNone = !!data.canRegisterNone;
    docs.editDepartments = data.editDepartments || [];
    docs.canEditNone = !!data.canEditNone;
    docs.types = data.types || [];
    docs.loaded = true;
    fillSuggestionLists();
    setExpiringBadge(data.expiringCount || 0);
    renderDocuments();
    if(!$("#page-expiring").hidden) loadExpiring();
  }catch(e){
    if(token !== docsToken || e.status === 401 || e.code === "password_change_required") return;
    $("#doc-table").replaceChildren(emptyBlock("Could not load the documents", errorWords(e)));
    $("#doc-count").textContent = "";
    $("#doc-pager").replaceChildren();
  }
}

function clearDocuments(){
  docs.all = []; docs.departments = []; docs.canRegisterNone = false; docs.editDepartments = []; docs.canEditNone = false; docs.types = [];
  docs.query = ""; docs.status = ""; docs.loaded = false; docs.page = 1; docs.pages = 1; docs.total = 0;
  docs.folders = []; docs.rootDocuments = 0; docs.folder = null;
  fillSuggestionLists();
  $("#doc-q").value = "";
  $("#doc-status").value = "";
  $("#doc-table").replaceChildren();
  $("#doc-crumbs").replaceChildren();
  $("#doc-pager").replaceChildren();
  $("#doc-count").textContent = "";
  clearExpiring();
}

function errorWords(e){
  if(e.code === "forbidden") return "Your role does not allow that.";
  return e.code === "offline" ? "The server did not answer. Check the network and try again." : "Something went wrong. Try again.";
}

function emptyBlock(title, text, action){
  return h("div", {class: "empty"},
    h("div", {class: "empty-ic"}, icon("docs")),
    h("h3", {}, title),
    h("p", {}, text),
    action ? h("div", {class: "row"}, action) : null);
}

// how a document's expiry date shows: the date, with a mark once it has passed or is close (the server says which)
function expiryMark(doc){
  const days = doc.daysLeft;
  return doc.expiryStatus === "expired" ? h("span", {class: "pill p-over"}, days === -1 ? "Expired yesterday" : "Expired " + plural(-days, "day") + " ago")
    : doc.expiryStatus === "expiring" ? h("span", {class: "pill p-due"}, days === 0 ? "Today" : "In " + plural(days, "day"))
    : null;
}
function expiryContent(doc){
  return h("span", {class: "exp"}, fmtDate(doc.expiresOn), expiryMark(doc));
}
function expiryCell(doc){
  return doc.expiresOn ? h("td", {}, expiryContent(doc)) : h("td", {class: "muted-cell"}, "-");
}

// the date of the last view or download (the time is in the hover text); "-" when never
function usedCell(at){
  return at ? h("td", {class: "nowrap", title: fmtStamp(at)}, fmtStamp(at).split(",")[0]) : h("td", {class: "muted-cell"}, "-");
}

/* ----- drag and drop: a document or a folder onto a folder, or onto a step of the path above ----- */
let dragging = null;
function makeDraggable(el, kind, id){
  el.draggable = true;
  el.addEventListener("dragstart", event => {
    dragging = {kind, id};
    event.dataTransfer.effectAllowed = "move";
    event.dataTransfer.setData("text/plain", kind + ":" + id);   // some browsers refuse to drag without data
    el.classList.add("dragging");
  });
  el.addEventListener("dragend", () => {
    dragging = null;
    el.classList.remove("dragging");
    for(const x of document.querySelectorAll(".drop-on")) x.classList.remove("drop-on");
  });
}
function makeDropTarget(el, folderId){
  el.addEventListener("dragover", event => {
    if(!dragging || (dragging.kind === "folder" && dragging.id === folderId)) return;
    event.preventDefault();
    event.dataTransfer.dropEffect = "move";
    el.classList.add("drop-on");
  });
  el.addEventListener("dragleave", event => { if(!el.contains(event.relatedTarget)) el.classList.remove("drop-on"); });
  el.addEventListener("drop", event => {
    event.preventDefault();
    el.classList.remove("drop-on");
    const item = dragging;
    dragging = null;
    if(item) moveItem(item, folderId);
  });
}

async function moveItem(item, target){
  try{
    if(item.kind === "doc") await api("PATCH", "/api/documents/" + item.id, {folder_id: target});
    else await api("PATCH", "/api/folders/" + item.id, {parent_id: target});
    toast("Moved to " + (target === null ? "the top level" : (folderOf(target) || {name: "the folder"}).name));
    await loadDocuments();
  }catch(e){
    if(e.status === 401) return;
    toast(e.code === "folder_taken" ? "That folder already has something with the same name."
      : e.code === "forbidden" ? "Your role does not allow moving that."
      : e.code === "invalid" && e.data && e.data.message === "inside_itself" ? "A folder cannot go inside itself."
      : e.code === "invalid" && e.data && e.data.message === "depth" ? "Folders can only go 12 levels deep."
      : errorWords(e), "warn");
  }
}

/* ----- folders: open, make, rename, remove ----- */
function openFolder(id){
  docs.folder = id;
  docs.query = ""; docs.status = "";
  $("#doc-q").value = ""; $("#doc-status").value = "";
  loadDocuments(1);
}

function folderError(e, name, fail){
  if(e.status === 401) return;
  if(e.code === "folder_taken") return fail("There is already a folder with that name here.", name);
  if(e.code === "invalid") return fail(e.data && e.data.message === "depth" ? "Folders can only go 12 levels deep." : "Enter a name of up to 100 characters, without a slash.", name);
  if(e.code === "folder_not_empty") return fail("It still has documents or folders in it. Move them out first.");
  fail(errorWords(e));
}

function openNewFolder(){
  const {box, fail, clear} = formError();
  const name = h("input", {id: "fld-fname", name: "name", type: "text", maxlength: "100", autocomplete: "off"});
  const where = docs.folder === null ? "at the top level" : "inside " + (folderOf(docs.folder) || {name: "this folder"}).name;
  const body = h("form", {novalidate: true, onsubmit: e => e.preventDefault()}, box,
    formField("fname", "Folder name", name, "It will be made " + where + ". Drag documents and folders into it afterwards.", true));
  openModal("New folder", body, async () => {
    clear();
    if(!name.value.trim()) return fail("Enter a name.", name);
    try{
      await api("POST", "/api/folders", {name: name.value, parent_id: docs.folder});
      closeModal();
      toast("Made the folder " + name.value.trim().replace(/\s+/g, " "));
      await loadDocuments();
    }catch(e){ folderError(e, name, fail); }
  }, "Make folder");
}

function openRenameFolder(f){
  const {box, fail, clear} = formError();
  const name = h("input", {id: "fld-fname", name: "name", type: "text", maxlength: "100", value: f.name, autocomplete: "off"});
  const body = h("form", {novalidate: true, onsubmit: e => e.preventDefault()}, box, formField("fname", "Folder name", name, "", true));
  openModal("Rename " + f.name, body, async () => {
    clear();
    if(!name.value.trim()) return fail("Enter a name.", name);
    try{
      await api("PATCH", "/api/folders/" + f.id, {name: name.value});
      closeModal();
      toast("Renamed");
      await loadDocuments();
    }catch(e){ folderError(e, name, fail); }
  }, "Rename");
}

function confirmDeleteFolder(f){
  const {box, fail, clear} = formError();
  const full = f.documents > 0 || f.folders > 0;
  const body = h("div", {}, box, h("p", {}, full
    ? f.name + " still has " + (f.folders ? plural(f.folders, "folder") : "") + (f.folders && f.documents ? " and " : "") + (f.documents ? plural(f.documents, "document") : "") + " in it. Move them out first; only an empty folder can be removed."
    : "Remove the empty folder " + f.name + "? Nothing else changes. The removal stays in Audits."));
  openModal("Remove " + f.name + "?", body, full ? null : async () => {
    clear();
    try{
      await api("DELETE", "/api/folders/" + f.id);
      closeModal();
      toast("Removed " + f.name);
      await loadDocuments();
    }catch(e){ folderError(e, null, fail); }
  }, "Remove", {cancelLabel: full ? "Close" : "Cancel"});
}

/* ----- drawing the list ----- */
const canKeepFolders = () => can("documentator.document.create");

function renderCrumbs(){
  const slot = $("#doc-crumbs");
  if(searching()){
    slot.replaceChildren(h("span", {class: "crumb", "aria-current": "true"}, "Search results from every folder"),
      h("button", {class: "btn sm", type: "button", onclick: () => { docs.query = ""; docs.status = ""; $("#doc-q").value = ""; $("#doc-status").value = ""; loadDocuments(1); }}, "Back to the folders"));
    return;
  }
  const trail = folderTrail(docs.folder);
  const step = (label, id, current) => {
    const b = h("button", {class: "crumb", type: "button", "aria-current": current ? "true" : null, onclick: current ? null : () => openFolder(id)}, label);
    if(!current) makeDropTarget(b, id);
    return b;
  };
  slot.replaceChildren(step("Documents", null, trail.length === 0),
    ...trail.flatMap((f, i) => [icon("chevron-right"), step(f.name, f.id, i === trail.length - 1)]));
}

function folderCounts(f){
  return [f.folders ? plural(f.folders, "folder") : "", plural(f.documents, "document")].filter(Boolean).join(", ");
}

function folderActions(f){
  if(!canKeepFolders()) return null;
  return [h("button", {class: "btn sm", type: "button", onclick: event => { event.stopPropagation(); openRenameFolder(f); }}, "Rename"),
          h("button", {class: "btn sm", type: "button", onclick: event => { event.stopPropagation(); confirmDeleteFolder(f); }}, "Remove")];
}

function renderDocuments(){
  renderCrumbs();
  const browsing = !searching();
  // the folders of this level are shown once, on the first page
  const folders = browsing && docs.page === 1 ? childFolders(docs.folder) : [];
  const total = docs.total + (browsing ? childFolders(docs.folder).length : 0);
  $("#doc-count").textContent = total === 0 ? "" : searching() ? plural(docs.total, "document") + " found" : plural(docs.total, "document") + (childFolders(docs.folder).length ? ", " + plural(childFolders(docs.folder).length, "folder") : "");
  renderPager($("#doc-pager"), docs, page => loadDocuments(page));
  for(const b of document.querySelectorAll("#doc-view button")) b.setAttribute("aria-pressed", String(b.dataset.view === docs.view));

  if(docs.all.length === 0 && folders.length === 0){
    $("#doc-table").replaceChildren(searching()
      ? emptyBlock("Nothing matches", "No document matches the words and the expiry filter you chose.")
      : docs.folder === null && docs.rootDocuments === 0 && docs.folders.length === 0
        ? emptyBlock("No documents yet", can("documentator.document.create") ? "Upload the first one. Each document gets a code, and every upload of it is kept as a version. Make folders to keep them tidy." : "Nothing has been registered yet.",
            can("documentator.document.create") ? h("button", {class: "btn primary", type: "button", onclick: openUpload}, icon("upload"), "Upload document") : null)
        : emptyBlock("This folder is empty", can("documentator.document.create") ? "Upload a document here, or drag documents and folders into it." : "Nothing is in this folder."));
    return;
  }
  $("#doc-table").replaceChildren(docs.view === "grid" ? gridView(folders, browsing) : listView(folders, browsing));
}

function openDocument(doc){ openDetail(doc.id); }
function onEnter(action){ return event => { if(event.key === "Enter" || event.key === " "){ event.preventDefault(); action(); } }; }

function listView(folders, browsing){
  const th = (label, cls) => h("th", {scope: "col", class: cls}, label);
  const dash = text => text ? h("td", {class: "nowrap"}, text) : h("td", {class: "muted-cell"}, "-");
  const columns = browsing ? 11 : 12;

  const folderRows = folders.map(f => {
    const row = h("tr", {class: "folder-row", tabindex: "0", onclick: () => openFolder(f.id), onkeydown: onEnter(() => openFolder(f.id))},
      h("td", {class: "wrap", colspan: "2"}, h("span", {class: "row-ic"}, icon("folder")), h("span", {class: "c-title"}, f.name)),
      h("td", {class: "folder-info", colspan: String(columns - 3)}, folderCounts(f)),
      h("td", {class: "usr-acts"}, folderActions(f)));
    makeDropTarget(row, f.id);
    if(canKeepFolders()) makeDraggable(row, "folder", f.id);
    return row;
  });

  const docRows = docs.all.map(doc => {
    const row = h("tr", {tabindex: "0", onclick: () => openDocument(doc), onkeydown: onEnter(() => openDocument(doc))},
      h("td", {class: "mono"}, h("span", {class: "tag"}, doc.code)),
      h("td", {class: "wrap"}, h("div", {class: "c-title"}, doc.title)),
      dash(doc.type),
      dash(doc.department),
      browsing ? null : dash(doc.folder),
      dash(fmtDate(doc.issuedOn)),
      expiryCell(doc),
      h("td", {}, h("span", {class: "pill p-mute"}, "Ver " + doc.rev),
        doc.archivedVersions ? h("span", {class: "pill p-accent arch-note", title: "Older versions of this document that are archived"}, doc.archivedVersions + " archived") : null),
      h("td", {class: "num"}, String(doc.fileCount)),
      usedCell(doc.lastViewedAt),
      usedCell(doc.lastDownloadedAt),
      h("td", {}, h("div", {}, doc.createdBy), h("div", {class: "sub"}, fmtStamp(doc.createdAt))));
    if(doc.can && doc.can.update) makeDraggable(row, "doc", doc.id);
    return row;
  });

  return h("table", {class: "rt"},
    h("thead", {}, h("tr", {}, th("Code"), th("Title"), th("Type"), th("Department"), browsing ? null : th("Folder"), th("Issued"), th("Expires"), th("Ver"), th("Files", "r"), th("Last viewed"), th("Last downloaded"), th("Added"))),
    h("tbody", {}, folderRows, docRows));
}

function gridView(folders, browsing){
  const cards = [];
  if(folders.length) cards.push(h("div", {class: "g-label"}, "Folders"));
  for(const f of folders){
    const card = h("div", {class: "gcard folder", tabindex: "0", onclick: () => openFolder(f.id), onkeydown: onEnter(() => openFolder(f.id))},
      h("div", {class: "g-ic"}, icon("folder")),
      h("div", {class: "g-title"}, f.name),
      h("div", {class: "g-sub"}, folderCounts(f)),
      canKeepFolders() ? h("div", {class: "g-acts"}, folderActions(f)) : null);
    makeDropTarget(card, f.id);
    if(canKeepFolders()) makeDraggable(card, "folder", f.id);
    cards.push(card);
  }
  if(docs.all.length && folders.length) cards.push(h("div", {class: "g-label"}, "Documents"));
  for(const doc of docs.all){
    const card = h("div", {class: "gcard", tabindex: "0", onclick: () => openDocument(doc), onkeydown: onEnter(() => openDocument(doc))},
      h("div", {class: "g-ic"}, icon("docs"), h("span", {class: "tag"}, doc.code)),
      h("div", {class: "g-title"}, doc.title),
      h("div", {class: "g-sub"}, [doc.type, doc.department, browsing ? "" : doc.folder].filter(Boolean).join(" · ")),
      h("div", {class: "g-pills"}, h("span", {class: "pill p-mute"}, "Ver " + doc.rev), doc.expiresOn ? (expiryMark(doc) || h("span", {class: "pill p-mute"}, "Expires " + fmtDate(doc.expiresOn))) : null));
    if(doc.can && doc.can.update) makeDraggable(card, "doc", doc.id);
    cards.push(card);
  }
  return h("div", {class: "gridv"}, cards);
}

let docsTimer = null;
$("#doc-q").addEventListener("input", event => {
  docs.query = event.target.value;
  clearTimeout(docsTimer);
  docsTimer = setTimeout(() => loadDocuments(1), 250);   // the server searches; wait for a pause in the typing
});
$("#doc-status").addEventListener("change", event => { docs.status = event.target.value; loadDocuments(1); });
$("#doc-new").addEventListener("click", () => openUpload());
$("#doc-newfolder").addEventListener("click", openNewFolder);
$("#doc-view").addEventListener("click", event => {
  const button = event.target.closest("button");
  if(!button) return;
  docs.view = button.dataset.view;
  try{ localStorage.setItem("documentator.docview.v1", docs.view); }catch(e){}
  renderDocuments();
});
