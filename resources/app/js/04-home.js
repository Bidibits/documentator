/* ---------- the signed-in page ---------- */
function showHome(user){
  $("#who-name").textContent = user.name || user.username;
  $("#signin").hidden = true;
  $("#app").hidden = false;
  document.documentElement.classList.remove("signed-out");
  loadDocuments();
}

async function signOut(){
  try{ await api("POST", "/api/logout"); }catch(e){}
  start("");
}
