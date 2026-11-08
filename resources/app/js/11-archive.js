/* ---------- the Archiving panel ---------- */
// Old versions (never a current one), the most recently opened first. Tick some and archive them by hand, or take
// archived ones back out. One request per version, so a long run shows its progress and one failure stops nothing else.
const arch = {rows: [], summary: null, filter: "all", query: "", selected: new Set(), loaded: false, busy: false,
              rules: null, edits: {}, lastRun: null, schedule: "", dueTotal: 0};

function clearArchive(){
  arch.rows = []; arch.summary = null; arch.filter = "all"; arch.query = ""; arch.selected = new Set(); arch.loaded = false;
  arch.rules = null; arch.edits = {}; arch.lastRun = null; arch.dueTotal = 0;
  $("#arch-rules").replaceChildren();
  $("#arch-table").replaceChildren();
  $("#arch-count").textContent = ""; $("#arch-summary").textContent = "";
  $("#arch-q").value = ""; $("#arch-filter").value = "all";
  syncArchiveButtons();
}

async function loadArchive(){
  // the rules work on every department at once, so only an administrator sees them
  $("#arch-rules").hidden = !can("documentator.archive.manage");
  if(can("documentator.archive.manage")) loadRules();
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

/* ---------- the rules: archive by themselves, every night ---------- */
const RULE_TEXT = {
  idle: {
    before: "Archive an old version that nobody has viewed or downloaded for",
    after: "",
    note: "A version that was never opened counts from the day it was uploaded.",
  },
  old: {
    before: "Archive an old version",
    after: "after a newer version replaced it",
    note: "0 means as soon as it is replaced.",
  },
};

async function loadRules(){
  try{
    const data = await api("GET", "/api/archive/rules");
    arch.rules = data.rules; arch.dueTotal = data.dueTotal; arch.lastRun = data.lastRun; arch.schedule = data.schedule;
    arch.edits = {};
    renderRules();
  }catch(e){
    if(e.status === 401) return;
    $("#arch-rules").replaceChildren(h("div", {class: "rules-body"}, h("p", {class: "hint"}, "The rules could not be loaded. " + errorWords(e))));
  }
}

// a rule as it is on screen: what has been saved, with anything changed but not yet saved on top
const ruleNow = rule => Object.assign({}, rule, arch.edits[rule.kind] || {});

function renderRules(){
  const error = h("div", {class: "banner error", role: "alert", hidden: true}, icon("alert-circle"), h("span", {}));
  const rows = arch.rules.map(rule => {
    const now = ruleNow(rule), text = RULE_TEXT[rule.kind];
    const edit = change => { arch.edits[rule.kind] = Object.assign({}, arch.edits[rule.kind] || {}, change); syncRuleButtons(); };
    const on = h("input", {type: "checkbox", id: "rule-" + rule.kind + "-on"});
    on.checked = now.enabled;
    on.addEventListener("change", () => edit({enabled: on.checked}));
    const amount = h("input", {type: "number", class: "rule-n", min: rule.kind === "old" ? "0" : "1", max: "999", value: String(now.amount), "aria-label": "How many"});
    amount.addEventListener("input", () => edit({amount: parseInt(amount.value, 10)}));
    const unit = h("select", {class: "rule-u", "aria-label": "Days, months or years"}, ["days", "months", "years"].map(u => h("option", {value: u}, u)));
    unit.value = now.unit;
    unit.addEventListener("change", () => edit({unit: unit.value}));
    return h("div", {class: "rule"},
      h("label", {for: "rule-" + rule.kind + "-on", class: "rule-on"}, on),
      h("div", {class: "rule-b"},
        h("div", {class: "rule-line"}, h("span", {}, text.before), amount, unit, text.after ? h("span", {}, text.after) : null),
        h("div", {class: "hint"}, text.note)),
      h("span", {class: "pill " + (rule.due ? "p-due" : "p-mute"), title: "Versions this rule would archive if it ran now"},
        rule.due ? plural(rule.due, "version") + " due now" : "none due"));
  });

  const run = arch.lastRun;
  const save = h("button", {class: "btn primary", id: "rules-save", type: "button", disabled: true}, "Save rules");
  const runNow = h("button", {class: "btn", id: "rules-run", type: "button"}, "Run the rules now");
  save.addEventListener("click", () => saveRules(error));
  runNow.addEventListener("click", () => confirmRun());
  $("#arch-rules").replaceChildren(h("div", {class: "rules-body"},
    h("div", {class: "rules-h"}, h("h3", {}, "Archive rules"), h("span", {class: "hint"}, "Run automatically " + arch.schedule + ". A current version is never archived.")),
    error, ...rows,
    h("div", {class: "rules-f"}, save, runNow,
      h("span", {class: "hint"}, run ? "Last run " + fmtStamp(run.at) + " by " + run.by + ": " + run.note + "." : "The rules have not run yet."))));
  syncRuleButtons();
}

// Save is for changes not yet saved; "Run now" is for what is due and saved (a rule edited but unsaved does not count)
function syncRuleButtons(){
  const save = $("#rules-save"), run = $("#rules-run");
  if(!save) return;
  const dirty = arch.rules.some(r => arch.edits[r.kind] && Object.keys(arch.edits[r.kind]).some(k => arch.edits[r.kind][k] !== r[k]));
  save.disabled = !dirty || arch.busy;
  run.disabled = arch.busy || dirty || arch.dueTotal === 0;
  run.title = dirty ? "Save the rules first" : arch.dueTotal === 0 ? "Nothing is due" : "";
}

async function saveRules(error){
  error.hidden = true;
  const changed = arch.rules.filter(r => arch.edits[r.kind] && Object.keys(arch.edits[r.kind]).some(k => arch.edits[r.kind][k] !== r[k]));
  for(const rule of changed){
    const now = ruleNow(rule);
    const min = rule.kind === "old" ? 0 : 1;
    if(!Number.isInteger(now.amount) || now.amount < min || now.amount > 999){
      error.querySelector("span").textContent = "The number must be " + (rule.kind === "old" ? "0 to 999 (0 means as soon as it is replaced)." : "1 to 999.");
      error.hidden = false;
      return;
    }
  }
  try{
    for(const rule of changed){
      const now = ruleNow(rule);
      await api("PUT", "/api/archive/rules/" + rule.kind, {enabled: now.enabled, amount: now.amount, unit: now.unit});
    }
    toast("Rules saved");
    await loadRules();
  }catch(e){
    if(e.status === 401) return;
    error.querySelector("span").textContent = e.code === "invalid" ? "One of the rules is not valid. Check the numbers." : errorWords(e);
    error.hidden = false;
  }
}

function confirmRun(){
  if(arch.dueTotal === 0) return;
  const progress = h("p", {class: "hint"});
  openModal("Run the archive rules now?",
    h("div", {}, h("p", {}, plural(arch.dueTotal, "version") + " " + (arch.dueTotal === 1 ? "is" : "are") + " due and will be archived now, the same as the nightly run would. You can take any of them out again."), progress),
    async () => {
      progress.textContent = "Archiving...";
      arch.busy = true; syncRuleButtons(); syncArchiveButtons();
      try{
        const result = await api("POST", "/api/archive/run");
        closeModal();
        toast(result.failed.length === 0 ? "Archived " + plural(result.archived, "version")
          : "Archived " + result.archived + ". " + result.failed[0].code + " Ver " + result.failed[0].rev + " could not be archived (" + result.failed[0].error.replace(/_/g, " ") + ")" +
            (result.failed.length > 1 ? " and " + (result.failed.length - 1) + " more" : ""), result.failed.length ? "warn" : undefined);
      }catch(e){
        if(e.status === 401) return;
        progress.textContent = errorWords(e);
        return;
      }finally{ arch.busy = false; }
      await Promise.all([loadArchive(), loadDocuments()]);
    }, "Run now");
}
