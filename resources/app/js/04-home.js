/* ---------- the signed-in page ---------- */
// what a role may do is decided by the server; this only hides what would be refused
function can(permission){ return !!currentUser && Array.isArray(currentUser.permissions) && currentUser.permissions.includes(permission); }

let currentUser = null;   // who is signed in, for the "last viewed by" text of a file just used
function showHome(user){
  currentUser = user;
  const name = user.name || user.username;
  $("#who-name").textContent = name;
  $("#who-av").textContent = name.trim().charAt(0).toUpperCase();
  $("#who-role").textContent = user.roleLabel || "Signed in";
  applyPermissions();
  $("#signin").hidden = true;
  $("#app").hidden = false;
  document.documentElement.classList.remove("signed-out");
  const first = pageFromHash();
  showPage(first);
  if(can("documentator.document.view") && first !== "documents" && first !== "expiring") loadDocuments();   // the Expiring count in the side bar
}

async function signOut(){
  try{ await api("POST", "/api/logout"); }catch(e){}
  start("");
}

// only the panels and buttons this role may use are shown
function applyPermissions(){
  for(const item of document.querySelectorAll("#tabs .nav-item")) item.hidden = !can(PAGE_RIGHTS[item.dataset.page]);
  for(const group of document.querySelectorAll("#tabs .sb-group")) group.hidden = ![...group.querySelectorAll(".nav-item")].some(i => !i.hidden);
  $("#doc-new").hidden = !can("documentator.document.create");
  $("#doc-newfolder").hidden = !can("documentator.document.create");
}
