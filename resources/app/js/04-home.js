/* ---------- the signed-in page ---------- */
let currentUser = null;   // who is signed in, for the "last viewed by" text of a file just used
function showHome(user){
  currentUser = user;
  $("#who-name").textContent = user.name || user.username;
  $("#signin").hidden = true;
  $("#app").hidden = false;
  document.documentElement.classList.remove("signed-out");
  showPage(pageFromHash());
}

async function signOut(){
  try{ await api("POST", "/api/logout"); }catch(e){}
  start("");
}
