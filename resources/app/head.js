try{
  var t = localStorage.getItem("documentator.theme.v1");
  if(t === "light" || t === "dark") document.documentElement.setAttribute("data-theme", t);
}catch(e){}
try{
  if(localStorage.getItem("documentator.rail.v1") === "1") document.documentElement.setAttribute("data-rail", "1");
}catch(e){}
