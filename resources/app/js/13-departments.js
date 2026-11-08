/* ---------- the Departments panel ---------- */
// Documents belong to a department. One is switched off, never deleted: its documents keep it, it just is not offered for
// new documents. Every change is a column change in Audits.
const dep = {rows: [], loaded: false};

function clearDepartments(){
  dep.rows = []; dep.loaded = false;
  $("#dep-table").replaceChildren();
  $("#dep-count").textContent = "";
}

async function loadDepartments(){
  try{
    const data = await api("GET", "/api/departments");
    dep.rows = data.departments || [];
    dep.loaded = true;
    renderDepartments();
  }catch(e){
    if(e.status === 401 || e.code === "password_change_required") return;
    $("#dep-table").replaceChildren(emptyBlock("Could not load the departments", errorWords(e)));
    $("#dep-count").textContent = "";
  }
}

function renderDepartments(){
  $("#dep-count").textContent = dep.rows.length === 0 ? "" : plural(dep.rows.length, "department");
  if(dep.rows.length === 0){
    $("#dep-table").replaceChildren(emptyBlock("No departments yet", "Add the first one, for example HR or Accounting. Documents can then be given a department.",
      h("button", {class: "btn primary", type: "button", onclick: openNewDepartment}, icon("plus"), "New department")));
    return;
  }
  const th = label => h("th", {scope: "col"}, label);
  const rows = dep.rows.map(d => h("tr", {},
    h("td", {class: "wrap"}, h("div", {class: "c-title"}, d.name)),
    h("td", {class: "num"}, String(d.documents)),
    h("td", {class: "nowrap"}, h("span", {class: "pill " + (d.active ? "p-ok" : "p-mute")}, d.active ? "In use" : "Switched off")),
    h("td", {class: "usr-acts"},
      h("button", {class: "btn sm", type: "button", onclick: () => openRenameDepartment(d)}, "Rename"),
      h("button", {class: "btn sm", type: "button", onclick: () => confirmDepartmentActive(d)}, d.active ? "Switch off" : "Switch on"))));
  $("#dep-table").replaceChildren(h("table", {class: "rt dep-table"},
    h("thead", {}, h("tr", {}, th("Name"), h("th", {scope: "col", class: "r"}, "Documents"), th("Status"), th(""))),
    h("tbody", {}, rows)));
}

function departmentError(e, name, fail){
  if(e.status === 401) return;
  if(e.code === "department_taken") return fail("A department with that name already exists.", name);
  if(e.code === "invalid") return fail("Enter a name of up to 100 characters.", name);
  return fail(errorWords(e));
}

function openNewDepartment(){
  const {box, fail, clear} = formError();
  const name = h("input", {id: "fld-name", name: "name", type: "text", maxlength: "100", autocomplete: "off"});
  const body = h("form", {novalidate: true, onsubmit: e => e.preventDefault()}, box, formField("name", "Name", name, "For example HR, Accounting or Legal.", true));
  openModal("New department", body, async () => {
    clear();
    if(!name.value.trim()) return fail("Enter a name.", name);
    try{
      const result = await api("POST", "/api/departments", {name: name.value});
      closeModal();
      toast("Added " + result.department.name);
      await loadDepartments();
    }catch(e){ departmentError(e, name, fail); }
  }, "Add");
}

function openRenameDepartment(d){
  const {box, fail, clear} = formError();
  const name = h("input", {id: "fld-name", name: "name", type: "text", maxlength: "100", value: d.name, autocomplete: "off"});
  const body = h("form", {novalidate: true, onsubmit: e => e.preventDefault()}, box,
    d.documents ? h("p", {class: "hint"}, "Its " + plural(d.documents, "document") + " will show the new name.") : null,
    formField("name", "Name", name, "", true));
  openModal("Rename " + d.name, body, async () => {
    clear();
    if(!name.value.trim()) return fail("Enter a name.", name);
    if(name.value.trim() === d.name){ closeModal(); return; }
    try{
      await api("PATCH", "/api/departments/" + d.id, {name: name.value});
      closeModal();
      toast("Renamed to " + name.value.trim().replace(/\s+/g, " "));
      await Promise.all([loadDepartments(), loadDocuments()]);
    }catch(e){ departmentError(e, name, fail); }
  }, "Rename");
}

function confirmDepartmentActive(d){
  const on = !d.active;
  const {box, fail, clear} = formError();
  const body = h("div", {}, box, h("p", {}, on
    ? d.name + " will be offered again when a document is registered."
    : d.name + " will no longer be offered when a document is registered." + (d.documents ? " Its " + plural(d.documents, "document") + " keep it." : "")));
  openModal(on ? "Switch " + d.name + " on?" : "Switch " + d.name + " off?", body, async () => {
    clear();
    try{
      await api("PATCH", "/api/departments/" + d.id, {active: on});
      closeModal();
      toast(d.name + (on ? " is in use again" : " is switched off"));
      await loadDepartments();
    }catch(e){ departmentError(e, null, fail); }
  }, on ? "Switch on" : "Switch off");
}

$("#dep-new").addEventListener("click", openNewDepartment);
