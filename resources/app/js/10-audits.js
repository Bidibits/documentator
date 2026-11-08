/* ---------- the Audits panel ---------- */
// Every recorded change, newest first (the newest 500 that match). Names stand in for ids. Choosing a record's name shows
// only that record's history, the way KVNsys's Audit log does.
const audits = {filters: {table: "", person: "", from: "", to: "", record: "", recordName: ""}, data: null, token: 0};

function clearAudits(){
  audits.filters = {table: "", person: "", from: "", to: "", record: "", recordName: ""};
  audits.data = null; audits.token++;
  $("#au-table-wrap").replaceChildren();
  $("#au-count").textContent = "";
  for(const id of ["#au-table", "#au-person"]) $(id).replaceChildren(h("option", {value: ""}, id === "#au-table" ? "All tables" : "Everyone"));
  $("#au-from").value = ""; $("#au-to").value = "";
  $("#au-one").hidden = true;
}

async function loadAudits(){
  const token = ++audits.token;
  const f = audits.filters;
  const query = Object.entries({table: f.table, record: f.record, person: f.person, from: f.from, to: f.to})
    .filter(([, v]) => v).map(([k, v]) => k + "=" + encodeURIComponent(v)).join("&");
  try{
    const data = await api("GET", "/api/audits" + (query ? "?" + query : ""));
    if(token !== audits.token) return;
    audits.data = data;
    fillAuditChoices();
    renderAudits();
  }catch(e){
    if(token !== audits.token || e.status === 401) return;
    if(e.code === "invalid"){
      $("#au-table-wrap").replaceChildren(emptyBlock("Check the filters",
        e.data.message === "order" ? "The first day comes after the last one. Swap them round." : "One of the filters is not valid."));
      $("#au-count").textContent = "";
      return;
    }
    $("#au-table-wrap").replaceChildren(emptyBlock("Could not load the audits", errorWords(e)));
    $("#au-count").textContent = "";
  }
}

// the Table and Person boxes offer what the log holds; what is chosen stays chosen
function fillAuditChoices(){
  const fill = (select, first, items) => {
    const keep = select.value;
    select.replaceChildren(h("option", {value: ""}, first), ...items.map(i => h("option", {value: i.value}, i.label)));
    select.value = items.some(i => i.value === keep) ? keep : "";
  };
  fill($("#au-table"), "All tables", audits.data.tables.map(t => ({value: t.table, label: t.label})));
  fill($("#au-person"), "Everyone", audits.data.people.map(p => ({value: p.id, label: p.name})));
  $("#au-table").value = audits.filters.table;
  $("#au-person").value = audits.filters.person;
}

function renderAudits(){
  const {entries, more} = audits.data;
  const f = audits.filters;
  $("#au-one").hidden = !f.record;
  $("#au-one").replaceChildren(...(f.record ? [
    "Only the history of " + (f.recordName || "record " + f.record) + " ",
    h("button", {class: "btn sm", type: "button", onclick: () => { f.record = ""; f.recordName = ""; loadAudits(); }}, "Show all")] : []));
  $("#au-count").textContent = entries.length === 0 ? "" : (more ? "Newest " + entries.length + " shown; narrow the filters to see older ones" : plural(entries.length, "change"));

  if(entries.length === 0){
    $("#au-table-wrap").replaceChildren(emptyBlock("No changes",
      f.table || f.person || f.from || f.to || f.record ? "No recorded change matches those filters." : "Nothing has been changed yet."));
    return;
  }

  const th = label => h("th", {scope: "col"}, label);
  const value = v => v === null || v === "" ? h("td", {class: "muted-cell"}, "-") : h("td", {class: "wrap au-val"}, v);
  let last = null;
  const rows = entries.map(e => {
    const same = e.saveId === last;   // the columns of one save read as one block: when and who only on its first row
    last = e.saveId;
    return h("tr", {class: same ? "au-same" : "au-first"},
      h("td", {class: "nowrap"}, same ? "" : fmtStamp(e.at)),
      h("td", {class: "nowrap"}, same ? "" : e.by || "-"),
      h("td", {class: "nowrap"}, e.tableLabel),
      h("td", {class: "wrap"}, e.record
        ? h("button", {class: "au-link", type: "button", title: "Show only this record's history", onclick: () => {
            audits.filters.table = e.table; audits.filters.record = e.recordId; audits.filters.recordName = e.record; loadAudits();
          }}, e.record)
        : h("span", {class: "muted-cell"}, "(gone) " + e.recordId)),
      h("td", {class: "nowrap"}, e.columnLabel),
      h("td", {}, h("span", {class: "pill " + (e.change === "Created" ? "p-ok" : e.change === "Deleted" ? "p-over" : "p-accent")}, e.change)),
      h("td", {class: "nowrap"}, e.source),
      value(e.before), value(e.after),
      h("td", {class: "nowrap mono-sm"}, same ? "" : e.where));
  });
  $("#au-table-wrap").replaceChildren(h("table", {class: "rt au-table"},
    h("thead", {}, h("tr", {}, th("When"), th("By"), th("Table"), th("Record"), th("Column"), th("Change"), th("Source"), th("Before"), th("After"), th("From"))),
    h("tbody", {}, rows)));
}

for(const [id, key] of [["#au-table", "table"], ["#au-person", "person"], ["#au-from", "from"], ["#au-to", "to"]]){
  $(id).addEventListener("change", event => {
    audits.filters[key] = event.target.value;
    if(key === "table"){ audits.filters.record = ""; audits.filters.recordName = ""; }
    loadAudits();
  });
}
$("#au-refresh").addEventListener("click", () => loadAudits());
