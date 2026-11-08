/* ---------- the sign-in screen ---------- */
const SIGNIN_HELP = "Use the username and password your administrator gave you.";
let signInDone = null;   // resolves with the user once someone has signed in

function showSignIn(message){
  return new Promise(resolve => {
    $("#si-msg").textContent = message || SIGNIN_HELP;
    $("#si-err").hidden = true;
    $("#si-pass").value = "";
    closeEverything();
    clearDocuments();
    clearAudits();
    clearArchive();
    clearUsers();
    clearDepartments();
    clearRoles();   // what the last person saw must not stay in the page for the next one
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

/* ---------- choosing a new password, the first time ---------- */
// An administrator made the account or set its password: the person must choose their own before anything else.
function showPasswordChange(user){
  return new Promise(resolve => {
    for(const id of ["#pw-cur", "#pw-new", "#pw-new2"]) $(id).value = "";
    $("#pw-err").hidden = true;
    closeEverything();
    $("#app").hidden = true;
    $("#signin").hidden = true;
    $("#pwchange").hidden = false;
    document.documentElement.classList.add("signed-out");
    passwordChanged = resolve;
    setTimeout(() => $("#pw-cur").focus(), 30);
  });
}
let passwordChanged = null;

function passwordError(text, field){
  const box = $("#pw-err");
  box.textContent = text;
  box.hidden = false;
  if(field) field.focus();
}

const PASSWORD_PROBLEMS = {
  password_short: "The new password needs at least 8 characters.",
  password_long: "The new password is too long.",
  password_username: "The new password cannot be your username.",
  password_same: "Choose a password different from the one you were given.",
};

async function passwordSubmit(event){
  event.preventDefault();
  const current = $("#pw-cur").value, next = $("#pw-new").value, again = $("#pw-new2").value, button = $("#pw-go");
  if(!current) return passwordError("Enter the password you were given.", $("#pw-cur"));
  if(!next) return passwordError("Enter a new password.", $("#pw-new"));
  if(next !== again) return passwordError("The two new passwords are different.", $("#pw-new2"));

  button.disabled = true;
  try{
    const result = await api("POST", "/api/password", {current: current, password: next});
    for(const id of ["#pw-cur", "#pw-new", "#pw-new2"]) $(id).value = "";
    $("#pwchange").hidden = true;
    const done = passwordChanged;
    passwordChanged = null;
    if(done) done(result.user);
  }catch(e){
    if(e.status === 401) return;
    if(e.code === "invalid" && e.data.field === "current") passwordError("That is not the password you were given.", $("#pw-cur"));
    else if(e.code === "invalid") passwordError(PASSWORD_PROBLEMS[e.data.message] || "That password is not allowed.", $("#pw-new"));
    else passwordError(errorText(e));
  }finally{
    button.disabled = false;
  }
}
