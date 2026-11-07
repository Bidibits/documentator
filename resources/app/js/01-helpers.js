/* ---------- small helpers ---------- */
const $ = selector => document.querySelector(selector);
const plural = (n, word) => n + " " + word + (n === 1 ? "" : "s");

// the server's error codes, in words
function errorText(e){
  switch(e.code){
    case "locked": return "Too many tries. Wait " + plural(Number(e.data && e.data.minutes) || 15, "minute") + " and try again.";
    case "inactive": return "This account is switched off. Ask an administrator.";
    case "bad_login": return "That username and password do not match.";
    case "offline": return "The server did not answer. Check the network and try again.";
    default: return "Could not sign in. Try again.";
  }
}
