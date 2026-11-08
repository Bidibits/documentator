/* ---------- start ---------- */
// Asks the server who is signed in; shows the sign-in screen when nobody is, and again whenever the server stops
// knowing this browser (signed out by an administrator, a switched-off account, a long break).
async function start(message){
  let user = null;
  try{ user = (await api("GET", "/api/session")).user; }catch(e){ user = null; }
  if(!user) user = await showSignIn(message);
  if(user.mustChangePassword) user = await showPasswordChange(user);
  document.documentElement.classList.remove("signed-out");
  showHome(user);
}

onSessionEnded = () => {
  if(signInDone) return;
  start("You were signed out. Sign in again to carry on.");
};

// the server will not do anything for this person until they have chosen a password of their own
onPasswordNeeded = () => { if(!passwordChanged) start(""); };

$("#si-form").addEventListener("submit", signInSubmit);
$("#pw-form").addEventListener("submit", passwordSubmit);
$("#pw-out").addEventListener("click", signOut);
$("#who-out").addEventListener("click", signOut);
start("");
