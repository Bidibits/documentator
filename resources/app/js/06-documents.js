/* ---------- the documents list ---------- */
const docs = {all: [], departments: [], types: [], query: "", loaded: false};

// offered in the Type box besides the types already in use (a plain list for now; an admin-kept list can come later)
const DEFAULT_TYPES = ["Contract", "MOA", "Permit / License", "Form", "Template", "Policy", "Memorandum",
                       "Board resolution", "Secretary's certificate", "Certificate", "Letter", "Report", "Other"];

function fillSuggestionLists(){
  const types = [...new Set([...DEFAULT_TYPES, ...docs.types])];
  $("#dl-types").replaceChildren(...types.map(t => h("option", {value: t})));
  $("#dl-depts").replaceChildren(...docs.departments.map(d => h("option", {value: d})));
}

async function loadDocuments(){
  try{
    const data = await api("GET", "/api/documents");
    docs.all = data.documents || [];
    docs.departments = data.departments || [];
    docs.types = data.types || [];
    docs.loaded = true;
    fillSuggestionLists();
    renderDocuments();
  }catch(e){
    if(e.status === 401) return;   // the sign-in screen is already coming up
    $("#doc-table").replaceChildren(emptyBlock("Could not load the documents", errorWords(e)));
    $("#doc-count").textContent = "";
  }
}

function clearDocuments(){
  docs.all = []; docs.departments = []; docs.types = []; docs.query = ""; docs.loaded = false;
  fillSuggestionLists();
  $("#doc-q").value = "";
  $("#doc-table").replaceChildren();
  $("#doc-count").textContent = "";
}

function errorWords(e){
  return e.code === "offline" ? "The server did not answer. Check the network and try again." : "Something went wrong. Try again.";
}

function emptyBlock(title, text, action){
  return h("div", {class: "empty"},
    h("div", {class: "empty-ic"}, icon("docs")),
    h("h3", {}, title),
    h("p", {}, text),
    action ? h("div", {class: "row"}, action) : null);
}

function matches(doc, query){
  const words = query.toLowerCase().split(/\s+/).filter(Boolean);
  const hay = [doc.code, doc.title, doc.type, doc.department, doc.location].join(" \n ").toLowerCase();
  return words.every(w => hay.includes(w));
}

// how a document's expiry date shows: the date, with a mark once it has passed or is within 30 days
function expiryContent(doc){
  const days = daysUntil(doc.expiresOn);
  const mark = days < 0 ? h("span", {class: "pill p-over"}, "Expired")
    : days <= 30 ? h("span", {class: "pill p-due"}, days === 0 ? "Today" : "In " + plural(days, "day"))
    : null;
  return h("span", {class: "exp"}, fmtDate(doc.expiresOn), mark);
}
function expiryCell(doc){
  return doc.expiresOn ? h("td", {}, expiryContent(doc)) : h("td", {class: "muted-cell"}, "-");
}

// the date of the last view or download (the time is in the hover text); "-" when never
function usedCell(at){
  return at ? h("td", {class: "nowrap", title: fmtStamp(at)}, fmtStamp(at).split(",")[0]) : h("td", {class: "muted-cell"}, "-");
}

function renderDocuments(){
  const shown = docs.all.filter(d => matches(d, docs.query));
  $("#doc-count").textContent = docs.all.length === 0 ? "" :
    shown.length === docs.all.length ? plural(docs.all.length, "document") : shown.length + " of " + docs.all.length;

  if(docs.all.length === 0){
    $("#doc-table").replaceChildren(emptyBlock("No documents yet", "Upload the first one. Each document gets a code, and every upload of it is kept as a version.",
      h("button", {class: "btn primary", type: "button", onclick: openUpload}, icon("upload"), "Upload document")));
    return;
  }
  if(shown.length === 0){
    $("#doc-table").replaceChildren(emptyBlock("Nothing matches", "No document has all of those words in its code, title, type, department or location."));
    return;
  }

  const th = (label, cls) => h("th", {scope: "col", class: cls}, label);
  const dash = text => text ? h("td", {class: "nowrap"}, text) : h("td", {class: "muted-cell"}, "-");
  const rows = shown.map(doc => {
    const open = () => openDetail(doc.id);
    return h("tr", {tabindex: "0", onclick: open, onkeydown: e => { if(e.key === "Enter" || e.key === " "){ e.preventDefault(); open(); } }},
      h("td", {class: "mono"}, h("span", {class: "tag"}, doc.code)),
      h("td", {class: "wrap"}, h("div", {class: "c-title"}, doc.title)),
      dash(doc.type),
      dash(doc.department),
      dash(fmtDate(doc.issuedOn)),
      expiryCell(doc),
      h("td", {}, h("span", {class: "pill p-mute"}, "Ver " + doc.rev),
        doc.archivedVersions ? h("span", {class: "pill p-accent arch-note", title: "Older versions of this document that are archived"}, doc.archivedVersions + " archived") : null),
      h("td", {class: "num"}, String(doc.fileCount)),
      usedCell(doc.lastViewedAt),
      usedCell(doc.lastDownloadedAt),
      h("td", {}, h("div", {}, doc.createdBy), h("div", {class: "sub"}, fmtStamp(doc.createdAt))));
  });

  $("#doc-table").replaceChildren(h("table", {class: "rt"},
    h("thead", {}, h("tr", {}, th("Code"), th("Title"), th("Type"), th("Department"), th("Issued"), th("Expires"), th("Ver"), th("Files", "r"), th("Last viewed"), th("Last downloaded"), th("Added"))),
    h("tbody", {}, rows)));
}

$("#doc-q").addEventListener("input", event => { docs.query = event.target.value; renderDocuments(); });
$("#doc-new").addEventListener("click", () => openUpload());
