/* ---------- the Users panel ---------- */
// An administrator makes each account with a first password, and sets any later one. The person must change that
// password at their first sign-in (see showPasswordChange). Every change is a column change in Audits.
const usr = {rows: [], roles: [], query: "", loaded: false};

function clearUsers(){
  usr.rows = []; usr.roles = []; usr.query = ""; usr.loaded = false;
  $("#usr-table").replaceChildren();
  $("#usr-count").textContent = "";
  $("#usr-q").value = "";
}

async function loadUsers(){
  try{
    const data = await api("GET", "/api/users");
    usr.rows = data.users || [];
    usr.roles = data.roles || [];
    usr.loaded = true;
    renderUsers();
  }catch(e){
    if(e.status === 401 || e.code === "password_change_required") return;
    $("#usr-table").replaceChildren(emptyBlock("Could not load the users", errorWords(e)));
    $("#usr-count").textContent = "";
  }
}

function userMatches(u, query){
  const words = query.toLowerCase().split(/\s+/).filter(Boolean);
  const hay = [u.name, u.username, u.roleLabel].join(" \n ").toLowerCase();
  return words.every(w => hay.includes(w));
}

function renderUsers(){
  const shown = usr.rows.filter(u => userMatches(u, usr.query));
  $("#usr-count").textContent = usr.rows.length === 0 ? "" : shown.length === usr.rows.length ? plural(usr.rows.length, "user") : shown.length + " of " + usr.rows.length;
  if(shown.length === 0){
    $("#usr-table").replaceChildren(emptyBlock(usr.rows.length ? "Nothing matches" : "No users yet", usr.rows.length ? "No user has all of those words in their name, username or role." : "Make the first account."));
    return;
  }

  const th = label => h("th", {scope: "col"}, label);
  const rows = shown.map(u => {
    const me = currentUser && u.id === currentUser.id;
    return h("tr", {},
      h("td", {class: "wrap"}, h("div", {class: "c-title"}, u.name, me ? h("span", {class: "pill p-mute usr-you"}, "You") : null)),
      h("td", {class: "mono"}, h("span", {class: "tag"}, u.username)),
      h("td", {}, h("span", {class: "pill " + (u.role === "admin" ? "p-accent" : "p-mute")}, u.roleLabel)),
      h("td", {class: "nowrap"},
        h("span", {class: "pill " + (u.active ? "p-ok" : "p-over")}, u.active ? "Active" : "Switched off"),
        u.active && u.mustChangePassword ? h("span", {class: "pill p-due usr-flag", title: "They have not yet chosen their own password"}, "Must change password") : null,
        u.authSource !== "local" ? h("span", {class: "pill p-mute usr-flag"}, u.authSource) : null),
      u.lastLogin ? h("td", {class: "nowrap", title: fmtStamp(u.lastLogin)}, fmtStamp(u.lastLogin).split(",")[0]) : h("td", {class: "muted-cell"}, "never"),
      h("td", {class: "usr-acts"},
        h("button", {class: "btn sm", type: "button", onclick: () => openEditUser(u)}, "Edit"),
        !me && u.authSource === "local" ? h("button", {class: "btn sm", type: "button", onclick: () => openSetPassword(u)}, "Set password") : null,
        !me ? h("button", {class: "btn sm", type: "button", onclick: () => confirmActive(u)}, u.active ? "Switch off" : "Switch on") : null));
  });
  $("#usr-table").replaceChildren(h("table", {class: "rt usr-table"},
    h("thead", {}, h("tr", {}, th("Name"), th("Username"), th("Role"), th("Status"), th("Last sign-in"), th(""))),
    h("tbody", {}, rows)));
}

const USER_PROBLEMS = {
  password_short: "The password needs at least 8 characters.",
  password_long: "The password is too long.",
  password_username: "The password cannot be the same as the username.",
};

// turns a refused request into words, naming the field when there is one
function userError(e, fields, fail){
  if(e.status === 401) return;
  if(e.code === "username_taken") return fail("That username is already used. Choose another.", fields.username);
  if(e.code === "own_account") return fail("You cannot do that to your own account. Ask another administrator.");
  if(e.code === "last_admin") return fail("There must always be one active administrator.");
  if(e.code === "not_local") return fail("This account signs in through another system, so it has no password here.");
  if(e.code === "invalid"){
    const field = e.data.field;
    if(field === "username") return fail("A username is 3 to 32 letters, digits, dots, dashes or underscores.", fields.username);
    if(field === "name") return fail("Enter a name of up to 100 characters.", fields.name);
    if(field === "role") return fail("Choose a role.", fields.role);
    if(field === "password") return fail(USER_PROBLEMS[e.data.message] || "That password is not allowed.", fields.password);
  }
  return fail(errorWords(e));
}

function formError(){
  const box = h("div", {class: "banner error", role: "alert", hidden: true}, icon("alert-circle"), h("span", {}));
  const fail = (text, field) => { box.querySelector("span").textContent = text; box.hidden = false; box.scrollIntoView({block: "nearest"}); if(field) field.focus(); };
  return {box, fail, clear: () => { box.hidden = true; }};
}

function roleSelect(value){
  const select = h("select", {id: "fld-role", name: "role"}, usr.roles.map(r => h("option", {value: r.key}, r.label)));
  select.value = value;
  const note = h("span", {class: "hint"});
  const sync = () => { const r = usr.roles.find(x => x.key === select.value); note.textContent = r ? r.description : ""; };
  select.addEventListener("change", sync);
  sync();
  return {select, note};
}

function passwordField(){
  const input = h("input", {id: "fld-password", name: "password", type: "password", autocomplete: "new-password", maxlength: "128"});
  const show = h("input", {type: "checkbox", id: "fld-show"});
  show.addEventListener("change", () => { input.type = show.checked ? "text" : "password"; });
  return {input, show: h("label", {class: "usr-show", for: "fld-show"}, show, " Show it")};
}

function openNewUser(){
  const {box, fail, clear} = formError();
  const name = h("input", {id: "fld-name", name: "name", type: "text", maxlength: "100", autocomplete: "off"});
  const username = h("input", {id: "fld-username", name: "username", type: "text", maxlength: "32", autocomplete: "off", autocapitalize: "none", spellcheck: "false"});
  const {select, note} = roleSelect("viewer");
  const pw = passwordField();
  const body = h("form", {novalidate: true, onsubmit: e => e.preventDefault()}, box,
    formField("name", "Full name", name, "", true),
    formField("username", "Username", username, "They sign in with this. 3 to 32 letters, digits, dots, dashes or underscores. Not changed later.", true),
    formField("role", "Role", select, "", true), note,
    formField("password", "First password", h("div", {}, pw.input, pw.show), "At least 8 characters, and not the username. Tell the person; they must change it when they first sign in.", true));
  openModal("New user", body, async () => {
    clear();
    if(!name.value.trim()) return fail("Enter their name.", name);
    if(!username.value.trim()) return fail("Enter a username.", username);
    if(!pw.input.value) return fail("Enter a first password.", pw.input);
    try{
      const result = await api("POST", "/api/users", {name: name.value.trim(), username: username.value.trim(), role: select.value, password: pw.input.value});
      closeModal();
      toast("Made " + result.user.username + ". They choose their own password at first sign-in.");
      await loadUsers();
    }catch(e){ userError(e, {name, username, role: select, password: pw.input}, fail); }
  }, "Make account");
}

function openEditUser(u){
  const {box, fail, clear} = formError();
  const me = currentUser && u.id === currentUser.id;
  const name = h("input", {id: "fld-name", name: "name", type: "text", maxlength: "100", value: u.name, autocomplete: "off"});
  const {select, note} = roleSelect(u.role);
  select.disabled = !!me;
  const body = h("form", {novalidate: true, onsubmit: e => e.preventDefault()}, box,
    h("p", {class: "hint"}, "Username " + u.username + (u.authSource === "local" ? "" : ", signs in through " + u.authSource) + "."),
    formField("name", "Full name", name, "", true),
    formField("role", "Role", select, me ? "You cannot change your own role. Ask another administrator." : "", true), note);
  openModal("Edit " + u.username, body, async () => {
    clear();
    if(!name.value.trim()) return fail("Enter their name.", name);
    const change = {};
    if(name.value.trim() !== u.name) change.name = name.value.trim();
    if(!me && select.value !== u.role) change.role = select.value;
    if(Object.keys(change).length === 0){ closeModal(); return; }
    try{
      await api("PATCH", "/api/users/" + u.id, change);
      closeModal();
      toast("Saved " + u.username);
      await loadUsers();
    }catch(e){ userError(e, {name, role: select}, fail); }
  }, "Save");
}

function openSetPassword(u){
  const {box, fail, clear} = formError();
  const pw = passwordField();
  const body = h("form", {novalidate: true, onsubmit: e => e.preventDefault()}, box,
    h("p", {}, "Set a new password for " + u.name + " (" + u.username + "). They are signed out everywhere now, and must choose their own password when they sign in again."),
    formField("password", "New password", h("div", {}, pw.input, pw.show), "At least 8 characters, and not the username. Tell the person.", true));
  openModal("Set password", body, async () => {
    clear();
    if(!pw.input.value) return fail("Enter a password.", pw.input);
    try{
      await api("POST", "/api/users/" + u.id + "/password", {password: pw.input.value});
      closeModal();
      toast("Password set for " + u.username + ". They must change it at first sign-in.");
      await loadUsers();
    }catch(e){ userError(e, {password: pw.input}, fail); }
  }, "Set password");
}

function confirmActive(u){
  const on = !u.active;
  const {box, fail, clear} = formError();
  const body = h("div", {}, box, h("p", {}, on
    ? u.name + " (" + u.username + ") will be able to sign in again."
    : u.name + " (" + u.username + ") is signed out everywhere now and cannot sign in until you switch the account back on. Their documents and history stay."));
  openModal(on ? "Switch " + u.username + " on?" : "Switch " + u.username + " off?", body, async () => {
    clear();
    try{
      await api("PATCH", "/api/users/" + u.id, {active: on});
      closeModal();
      toast(u.username + (on ? " is on again" : " is switched off"));
      await loadUsers();
    }catch(e){ userError(e, {}, fail); }
  }, on ? "Switch on" : "Switch off");
}

$("#usr-new").addEventListener("click", openNewUser);
$("#usr-q").addEventListener("input", event => { usr.query = event.target.value; renderUsers(); });
