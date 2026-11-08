/* ---------- the Archiving panel ---------- */
// Old versions (never a current one), the most recently opened first. Tick some and archive them by hand, or take
// archived ones back out. One request per version, so a long run shows its progress and one failure stops nothing else.
const arch = {rows: [], summary: null, filter: "all", query: "", selected: new Set(), loaded: false, busy: false};

function clearArchive(){
  arch.rows = []; arch.summary = null; arch.filter = "all"; arch.query = ""; arch.selected = new Set(); arch.loaded = false;
  $("#arch-table").replaceChildren();
  $("#arch-count").textContent = ""; $("#arch-summary").textContent = "";
  $("#arch-q").value = ""; $("#arch-filter").value = "all";
  syncArchiveButtons();
}

async function loadArchive(){
  try{
    const data = await api("GET", "/api/archive");
    arch.rows = data.versions || [];
    arch.summary = data.summary;
    arch.loaded = true;
    // what was ticked stays ticked only while it is still in the list
    const ids = new Set(arch.rows.map(r => r.id));
    arch.selected = new Set([...arch.selected].filter(id => ids.has(id)));
    renderArchive();
  }catch(e){
    if(e.status === 401) return;
    $("#arch-table").replaceChildren(emptyBlock("Could not load the archive", errorWords(e)));
    $("#arch-count").textContent = ""; $("#arch-summary").textContent = "";
  }
}

function archiveShown(){
  const words = arch.query.toLowerCase().split(/\s+/).filter(Boolean);
  return arch.rows.filter(r => {
    if(arch.filter === "live" && r.archived) return false;
    if(arch.filter === "archived" && !r.archived) return false;
    const hay = [r.code, r.title, "ver " + r.rev, r.changeNote].join(" \n ").toLowerCase();
    return words.every(w => hay.includes(w));
  });
}

function renderArchive(){
  const shown = archiveShown();
  const sum = arch.summary;
  $("#arch-count").textContent = arch.rows.length === 0 ? "" :
    shown.length === arch.rows.length ? plural(arch.rows.length, "version") : shown.length + " of " + arch.rows.length;
  $("#arch-summary").textContent = !sum || sum.archived === 0 ? "" :
    plural(sum.archived, "version") + " archived: " + fmtSize(sum.archivedOriginalBytes) + " of files packed into " + fmtSize(sum.archivedZipBytes) +
    ". PDFs, Word, Excel, pictures and the like are already compressed, so zipping them saves little; text and CSV shrink a lot.";

  if(arch.rows.length === 0){
    $("#arch-table").replaceChildren(emptyBlock("No old versions yet",
      "Only versions that have been replaced by a newer one can be archived. Upload a new version of a document and its earlier one will appear here."));
    syncArchiveButtons();
    return;
  }
  if(shown.length === 0){
    $("#arch-table").replaceChildren(emptyBlock("Nothing matches", "No version matches that search and filter."));
    syncArchiveButtons();
    return;
  }

  const th = (label, cls) => h("th", {scope: "col", class: cls}, label);
  const all = h("input", {type: "checkbox", id: "arch-all", "aria-label": "Select every version shown"});
  all.checked = shown.every(r => arch.selected.has(r.id));
  all.addEventListener("change", () => {
    for(const r of shown){ if(all.checked) arch.selected.add(r.id); else arch.selected.delete(r.id); }
    renderArchive();
  });
  const when = at => at ? h("td", {class: "nowrap", title: fmtStamp(at)}, fmtStamp(at).split(",")[0]) : h("td", {class: "muted-cell"}, "never");
  const rows = shown.map(r => {
    const box = h("input", {type: "checkbox", "aria-label": "Select " + r.code + " Ver " + r.rev});
    box.checked = arch.selected.has(r.id);
    box.addEventListener("change", () => { if(box.checked) arch.selected.add(r.id); else arch.selected.delete(r.id); syncArchiveButtons(); all.checked = shown.every(x => arch.selected.has(x.id)); });
    return h("tr", {class: box.checked ? "sel" : ""},
      h("td", {class: "ck"}, box),
      h("td", {class: "mono"}, h("span", {class: "tag"}, r.code)),
      h("td", {class: "wrap"}, h("div", {class: "c-title"}, r.title), r.changeNote ? h("div", {class: "sub"}, r.changeNote) : null),
      h("td", {}, h("span", {class: "pill p-mute"}, "Ver " + r.rev)),
      h("td", {class: "num"}, String(r.files)),
      h("td", {class: "nowrap"}, fmtSize(r.size)),
      when(r.lastViewedAt), when(r.lastDownloadedAt),
      h("td", {class: "nowrap"}, r.archived
        ? [h("span", {class: "pill p-accent"}, "Archived"), h("div", {class: "sub"}, fmtDate(r.archivedAt.slice(0, 10)) + (r.archivedBy ? " by " + r.archivedBy : "") + ", " + fmtSize(r.archiveSize))]
        : h("span", {class: "pill p-ok"}, "Live")));
  });
  $("#arch-table").replaceChildren(h("table", {class: "rt arch-table"},
    h("thead", {}, h("tr", {}, h("th", {scope: "col", class: "ck"}, all), th("Document"), th("Title"), th("Ver"), th("Files", "r"), th("Size"), th("Last viewed"), th("Last downloaded"), th("Status"))),
    h("tbody", {}, rows)));
  syncArchiveButtons();
}

// the two buttons act on what is ticked and fits them: only live versions can be archived, only archived ones taken out
function ticked(archived){ return arch.rows.filter(r => arch.selected.has(r.id) && r.archived === archived); }
function syncArchiveButtons(){
  const live = ticked(false).length, packed = ticked(true).length;
  $("#arch-do").disabled = arch.busy || live === 0;
  $("#arch-undo").disabled = arch.busy || packed === 0;
  $("#arch-do-lbl").textContent = live ? "Archive selected (" + live + ")" : "Archive selected";
  $("#arch-undo").textContent = packed ? "Take out of archive (" + packed + ")" : "Take out of archive";
}

function archiveErrorWords(e, row){
  const name = row.code + " Ver " + row.rev;
  if(e.code === "current_version") return name + " is a current version, which cannot be archived.";
  if(e.code === "file_missing") return name + ": a file" + (e.data && e.data.file ? " (" + e.data.file + ")" : "") + " is missing from the server's storage, so it was left as it was.";
  if(e.code === "archive_missing") return name + ": its zip is missing from the server's storage.";
  if(e.code === "archive_failed") return name + ": the zip could not be made or did not match the files, so nothing was changed.";
  if(e.code === "already_archived" || e.code === "not_archived") return null;   // already as wanted: not a problem
  return name + ": " + errorWords(e);
}

// runs one request per version, with the count shown in the open window, then reports
async function runArchive(kind, versions, progress){
  const problems = [];
  let done = 0;
  arch.busy = true; syncArchiveButtons();
  try{
    for(const row of versions){
      progress.textContent = (kind === "archive" ? "Archiving " : "Taking out ") + (done + 1) + " of " + versions.length + ": " + row.code + " Ver " + row.rev + "...";
      try{
        await api("POST", "/api/versions/" + row.id + "/" + (kind === "archive" ? "archive" : "restore"));
        done++;
      }catch(e){
        if(e.status === 401) return;
        const words = archiveErrorWords(e, row);
        if(words) problems.push(words); else done++;
      }
    }
  }finally{ arch.busy = false; }
  return {done, problems};
}

function confirmArchive(kind){
  const versions = ticked(kind === "restore");
  if(versions.length === 0) return;
  const archiving = kind === "archive";
  const progress = h("p", {class: "hint"});
  const bytes = versions.reduce((n, r) => n + r.size, 0);
  const body = h("div", {},
    h("p", {}, archiving
      ? plural(versions.length, "version") + " (" + fmtSize(bytes) + " of files) will be packed into zips. They can still be opened and downloaded, only slower, and you can take them out again."
      : plural(versions.length, "version") + " will be unpacked and become live again."),
    progress);
  openModal(archiving ? "Archive " + plural(versions.length, "version") + "?" : "Take " + plural(versions.length, "version") + " out of the archive?", body, async () => {
    const result = await runArchive(kind, versions, progress);
    if(!result) return;
    closeModal();
    await Promise.all([loadArchive(), loadDocuments()]);
    if(result.problems.length === 0){
      toast((archiving ? "Archived " : "Took out ") + plural(result.done, "version"));
    }else{
      toast((archiving ? "Archived " : "Took out ") + result.done + " of " + versions.length + ". " + result.problems[0] +
        (result.problems.length > 1 ? " (and " + (result.problems.length - 1) + " more)" : ""), "warn");
    }
  }, archiving ? "Archive" : "Take out");
}

$("#arch-do").addEventListener("click", () => confirmArchive("archive"));
$("#arch-undo").addEventListener("click", () => confirmArchive("restore"));
$("#arch-q").addEventListener("input", event => { arch.query = event.target.value; renderArchive(); });
$("#arch-filter").addEventListener("change", event => { arch.filter = event.target.value; renderArchive(); });
