/* ---------- the Roles panel ---------- */
// A role is a name and a grid: for each department (and for documents with no department) which of view / download /
// register / update / archive its people may do. A person has one role. The Administrator role is fixed.
const rol = {rows: [], departments: [], loaded: false};

const TAG_LABELS = {view: "View", download: "Download", register: "Register", update: "Update", archive: "Archive", delete: "Delete"};
const TAG_HINTS = {
  view: "See the document: its details, its versions and the names of its files",
  download: "Open and download its files",
  register: "Register new documents in this department",
  update: "Upload new versions of its documents",
  archive: "Archive its old versions, and take them out again",
  delete: "Reserved: there is no delete yet",
};

function clearRoles(){
  rol.rows = []; rol.departments = []; rol.loaded = false;
  $("#rol-table").replaceChildren();
  $("#rol-count").textContent = "";
}

async function loadRoles(){
  try{
    const data = await api("GET", "/api/roles");
    rol.rows = data.roles || [];
    rol.departments = data.departments || [];
    rol.tags = data.tags || [];
    rol.loaded = true;
    renderRoles();
  }catch(e){
    if(e.status === 401 || e.code === "password_change_required") return;
    $("#rol-table").replaceChildren(emptyBlock("Could not load the roles", errorWords(e)));
    $("#rol-count").textContent = "";
  }
}

// "HR: view, download; Accounting: view" for a role's grid, in a few words
function grantsSummary(role){
  if(role.system) return "Everything, in every department";
  const names = new Map(rol.departments.map(d => [String(d.id), d.name]));
  names.set("0", "No department");
  const parts = Object.entries(role.grants).filter(([, tags]) => tags.length)
    .map(([id, tags]) => (names.get(id) || "Department " + id) + ": " + tags.map(t => TAG_LABELS[t].toLowerCase()).join(", "));
  return parts.length ? parts.join("; ") : "Nothing ticked yet";
}

function renderRoles(){
  $("#rol-count").textContent = plural(rol.rows.length, "role");
  const th = label => h("th", {scope: "col"}, label);
  const rows = rol.rows.map(r => h("tr", {},
    h("td", {class: "wrap"}, h("div", {class: "c-title"}, r.name, r.system ? h("span", {class: "pill p-accent usr-flag"}, "Fixed") : null),
      r.description ? h("div", {class: "sub"}, r.description) : null),
    h("td", {class: "num"}, String(r.people)),
    h("td", {class: "wrap rol-sum"}, grantsSummary(r)),
    h("td", {class: "usr-acts"},
      h("button", {class: "btn sm primary", type: "button", onclick: () => openAccess(r)}, r.system ? "See access" : "Edit access"),
      r.system ? null : h("button", {class: "btn sm", type: "button", onclick: () => openEditRole(r)}, "Rename"),
      r.system ? null : h("button", {class: "btn sm", type: "button", disabled: r.people > 0, title: r.people > 0 ? "People still have this role" : "", onclick: () => confirmDeleteRole(r)}, "Delete"))));
  $("#rol-table").replaceChildren(h("table", {class: "rt rol-table"},
    h("thead", {}, h("tr", {}, th("Role"), h("th", {scope: "col", class: "r"}, "People"), th("May do"), th(""))),
    h("tbody", {}, rows)));
}

function roleError(e, name, fail){
  if(e.status === 401) return;
  if(e.code === "role_taken") return fail("A role with that name already exists.", name);
  if(e.code === "system_role") return fail("The Administrator role cannot be changed.");
  if(e.code === "role_in_use") return fail("Still given to " + plural(e.data.people, "person") + ". Change their role first.");
  if(e.code === "invalid") return fail("Enter a name of up to 60 characters.", name);
  return fail(errorWords(e));
}

function openNewRole(){
  const {box, fail, clear} = formError();
  const name = h("input", {id: "fld-name", name: "name", type: "text", maxlength: "60", autocomplete: "off"});
  const description = h("textarea", {id: "fld-description", name: "description", maxlength: "300", rows: "3"});
  const body = h("form", {novalidate: true, onsubmit: e => e.preventDefault()}, box,
    formField("name", "Name", name, "For example HR clerk, Accounting reader or Legal.", true),
    formField("description", "What it is for", description, "Shown when you choose a role for someone."));
  openModal("New role", body, async () => {
    clear();
    if(!name.value.trim()) return fail("Enter a name.", name);
    try{
      const result = await api("POST", "/api/roles", {name: name.value, description: description.value.trim() || null});
      closeModal();
      await loadRoles();
      toast("Made " + name.value.trim() + ". Now tick what it may do.");
      const made = rol.rows.find(r => r.id === result.id);
      if(made) openAccess(made);
    }catch(e){ roleError(e, name, fail); }
  }, "Make role");
}

function openEditRole(r){
  const {box, fail, clear} = formError();
  const name = h("input", {id: "fld-name", name: "name", type: "text", maxlength: "60", value: r.name, autocomplete: "off"});
  const description = h("textarea", {id: "fld-description", name: "description", maxlength: "300", rows: "3"}, r.description);
  const body = h("form", {novalidate: true, onsubmit: e => e.preventDefault()}, box,
    r.people ? h("p", {class: "hint"}, plural(r.people, "person") + " keep this role under its new name.") : null,
    formField("name", "Name", name, "", true),
    formField("description", "What it is for", description));
  openModal("Rename " + r.name, body, async () => {
    clear();
    if(!name.value.trim()) return fail("Enter a name.", name);
    try{
      await api("PATCH", "/api/roles/" + r.id, {name: name.value, description: description.value.trim() || null});
      closeModal();
      toast("Saved " + name.value.trim());
      await loadRoles();
    }catch(e){ roleError(e, name, fail); }
  }, "Save");
}

function confirmDeleteRole(r){
  const {box, fail, clear} = formError();
  openModal("Delete " + r.name + "?", h("div", {}, box, h("p", {}, "The role and its ticks are removed. Nobody has it now. The change stays in Audits.")), async () => {
    clear();
    try{
      await api("DELETE", "/api/roles/" + r.id);
      closeModal();
      toast("Deleted " + r.name);
      await loadRoles();
    }catch(e){ roleError(e, null, fail); }
  }, "Delete");
}

// the grid: a row per department (and one for documents with no department), a column per thing it may do
function openAccess(r){
  if(r.system){
    openModal("Access for " + r.name,
      h("div", {}, h("p", {}, "Administrators can do everything in every department, including departments made later, and they manage users, roles, departments, the audit log and the archive rules."),
        h("p", {class: "hint"}, "This role is fixed so that someone can always sign in and fix the others.")), null, "", {cancelLabel: "Close"});
    return;
  }

  const {box, fail, clear} = formError();
  const tags = ["view", "download", "register", "update", "archive", "delete"];
  const places = [...rol.departments.map(d => ({id: d.id, name: d.name, off: !d.active})), {id: 0, name: "No department", off: false}];
  const boxes = {};   // place id -> tag -> checkbox
  const ticked = id => (r.grants[String(id)] || []);

  const setBox = (id, tag, on) => { boxes[id][tag].checked = on; };
  const rowOn = (id, on) => { for(const t of tags) if(t !== "delete") setBox(id, t, on); };
  // you cannot do anything to what you cannot see: anything ticked brings View with it, and no View clears the row
  const onChange = (id, tag) => {
    if(tag === "view" && !boxes[id].view.checked) rowOn(id, false);
    else if(tag !== "view" && boxes[id][tag].checked) setBox(id, "view", true);
    sync();
  };
  const sync = () => {
    for(const p of places){
      const all = tags.filter(t => t !== "delete").every(t => boxes[p.id][t].checked);
      boxes[p.id].all.checked = all;
    }
    for(const t of tags){
      if(t === "delete") continue;
      boxes.col[t].checked = places.every(p => boxes[p.id][t].checked);
    }
  };

  boxes.col = {};
  const head = h("tr", {}, h("th", {scope: "col", class: "mx-dept"}, "Department"), h("th", {scope: "col", class: "mx-c"}, h("div", {class: "mx-t"}, "All")),
    tags.map(t => {
      const col = h("input", {type: "checkbox", "aria-label": "Tick every department for " + TAG_LABELS[t], disabled: t === "delete"});
      boxes.col[t] = col;
      col.addEventListener("change", () => { for(const p of places){ setBox(p.id, t, col.checked); if(t !== "view" && col.checked) setBox(p.id, "view", true); if(t === "view" && !col.checked) rowOn(p.id, false); } sync(); });
      return h("th", {scope: "col", class: "mx-c" + (t === "delete" ? " mx-off" : ""), title: TAG_HINTS[t]}, h("div", {class: "mx-t"}, TAG_LABELS[t]), col);
    }));

  const body = h("form", {novalidate: true, onsubmit: e => e.preventDefault()}, box,
    h("p", {class: "hint"}, "Tick what " + r.name + " may do in each department. Ticking anything brings View with it. " + plural(r.people, "person") + " currently " + (r.people === 1 ? "has" : "have") + " this role."),
    h("div", {class: "tbl-scroll"}, h("table", {class: "matrix"}, h("thead", {}, head),
      h("tbody", {}, places.map(p => {
        boxes[p.id] = {};
        const all = h("input", {type: "checkbox", "aria-label": "Tick everything for " + p.name});
        boxes[p.id].all = all;
        all.addEventListener("change", () => { rowOn(p.id, all.checked); sync(); });
        return h("tr", {class: p.off ? "mx-inactive" : ""},
          h("th", {scope: "row", class: "mx-dept"}, p.name, p.off ? h("span", {class: "pill p-mute usr-flag"}, "switched off") : null, p.id === 0 ? h("div", {class: "sub"}, "documents with no department") : null),
          h("td", {class: "mx-c"}, all),
          tags.map(t => {
            const cb = h("input", {type: "checkbox", "aria-label": TAG_LABELS[t] + " in " + p.name, disabled: t === "delete", title: TAG_HINTS[t]});
            cb.checked = ticked(p.id).includes(t);
            boxes[p.id][t] = cb;
            cb.addEventListener("change", () => onChange(p.id, t));
            return h("td", {class: "mx-c" + (t === "delete" ? " mx-off" : "")}, cb);
          }));
      })))),
    h("p", {class: "hint"}, "Delete is shown for later: there is no way to delete a document yet. Users, departments, roles, audits and the archive rules always need the Administrator role."));

  openModal("Access for " + r.name, body, async () => {
    clear();
    const grants = {};
    for(const p of places){
      const on = tags.filter(t => t !== "delete" && boxes[p.id][t].checked);
      if(on.length) grants[String(p.id)] = on;
    }
    try{
      await api("PATCH", "/api/roles/" + r.id, {grants: grants});
      closeModal();
      toast("Saved access for " + r.name);
      await loadRoles();
    }catch(e){ roleError(e, null, fail); }
  }, "Save access", {cls: "wide"});
  sync();
}

$("#rol-new").addEventListener("click", openNewRole);
