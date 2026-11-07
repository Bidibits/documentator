/* ---------- the sign-in screen ---------- */
const SIGNIN_HELP = "Use the username and password your administrator gave you.";
let signInDone = null;   // resolves with the user once someone has signed in

function showSignIn(message){
  return new Promise(resolve => {
    $("#si-msg").textContent = message || SIGNIN_HELP;
    $("#si-err").hidden = true;
    $("#si-pass").value = "";
    closeEverything();
    clearDocuments();   // what the last person saw must not stay in the page for the next one
    $("#app").hidden = true;
    $("#signin").hidden = false;
    document.documentElement.classList.add("signed-out");
    signInDone = resolve;
    setTimeout(() => ($("#si-user").value ? $("#si-pass") : $("#si-user")).focus(), 30);
  });
}

function signInError(text){
  const box = $("#si-err");
  box.textContent = text;
  box.hidden = false;
}

async function signInSubmit(event){
  event.preventDefault();
  const username = $("#si-user").value.trim(), password = $("#si-pass").value, button = $("#si-go");
  if(!username || !password){ signInError("Enter your username and password."); return; }

  button.disabled = true;
  try{
    const result = await api("POST", "/api/login", {username: username, password: password});
    $("#si-pass").value = "";
    $("#signin").hidden = true;
    document.documentElement.classList.remove("signed-out");
    const done = signInDone;
    signInDone = null;
    if(done) done(result.user);
  }catch(e){
    signInError(errorText(e));
    $("#si-pass").select();
  }finally{
    button.disabled = false;
  }
}
