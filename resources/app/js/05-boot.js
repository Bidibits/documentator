/* ---------- start ---------- */
// Asks the server who is signed in; shows the sign-in screen when nobody is, and again whenever the server stops
// knowing this browser (signed out by an administrator, a switched-off account, a long break).
async function start(message){
  let user = null;
  try{ user = (await api("GET", "/api/session")).user; }catch(e){ user = null; }
  if(!user) user = await showSignIn(message);
  showHome(user);
}

onSessionEnded = () => {
  if(signInDone) return;
  start("You were signed out. Sign in again to carry on.");
};

$("#si-form").addEventListener("submit", signInSubmit);
$("#who-out").addEventListener("click", signOut);
start("");
