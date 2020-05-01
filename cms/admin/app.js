$ = require('jquery'); // koj ce mi ovo qrc
jQuery = $;
var Ractive  = require("ractive");
window.Ractive = Ractive;
require('./js/selectize.min.js');
var Cropper = require('cropperjs');
window.Cropper = Cropper

var decoratorsSortable = require( './ractive-decorators-sortable.js' );
//console.log('decoratorsSortable', decoratorsSortable)

window.GlobalIncrementer = 1;
/*
var CSSJS = require("jotform-css.js");
var CSS = new CSSJS.cssjs();
SummerNoteSharedStyle = []
fetch('../sharedstyle.css')
  .then(response => response.text())
  .then((cssString) => {
    console.log(cssString)
    var parsed = CSS.parseCSS(cssString);
    console.log(parsed);
    parsed.forEach(function(r){
      SummerNoteSharedStyle.push(r.selector.substring(1)) // TODO: need to traverse subStyles recursively
      // save to key/val, trim . , trim after :...
    })
  })
*/
Ractive.DEBUG = !!(window.location.hostname == "127.0.0.1" || window.location.hostname == "localhost");
//HOSTNAME = Ractive.DEBUG?'http://test.mobilearea.info/test/':'';
ISMOBILE = (window.innerWidth <= 800);
window.addEventListener('resize', function(event){
    ISMOBILE = (window.innerWidth <= 800);
});
var anime =  require('animejs');
window.anime = anime
window.rt = require('ractive-touch');
var accounting = require('accounting')
//var Instascan = require('instascan');
//window.Instascan = Instascan;
var izitoast = require('izitoast');
window.izitoast = izitoast;
izitoast.settings({
    progressBar:false
    , position: 'bottomLeft'
    , closeOnClick:true
    , timeout:2000
});


window.fetch2 = function(url, obj){
//    var body = JSON.stringify(obj);
  //console.log('fetch', this)
  // TODO: Zamijeni ovo sranje s necim boljim
  var body = new FormData()
  if (obj)
      Object.keys(obj).forEach(function(key) {
        var tmpObj = (typeof obj[key] === 'undefined' || obj[key]===null)?'':obj[key]
        if (typeof tmpObj == 'object') tmpObj=JSON.stringify(tmpObj)
        body.append(key, tmpObj)
      });
  var self =  (this instanceof Ractive)?this:null;
  if (self) self.set('loading', true)
  
  return new Promise(function(resolve, reject) {
    var response = fetch(HOSTNAME+'api.php/'+url, {
        method: 'post',
        credentials:'same-origin',
//            headers: {
//             'Accept': 'application/json',
          'Content-Type': 'application/json',
//              "authorization":'Bearer '+ authorization
//            },
        body: body
      }).then(function(response){ 
            if (self) self.set('loading', false)
            return response.json()
        })
      .then(function(j){ 
          //console.log('j',j); 
          if (j && j._message_action == "reload")  ractive.set('is_logedin',false)//document.location = document.location; // missing session
          resolve([j,null]) 
        })
      .catch(function(err){
          if (self) self.set('loading', false)
          //console.log('nework error!', err);
          izitoast.error({ message: 'Network error!!!'});
          resolve([null,err])
      })
    })
}

//Ractive.events.tap = require( 'ractive-events-tap' );
//var anime = require('animejs');

//import Ractive from 'ractive';

//Ractive.defaults.isolated=true;
Ractive.prototype.unset = function(keypath){
    var lastDot = keypath.lastIndexOf( '.' ),
        parent = keypath.substr( 0, lastDot ),
        property = keypath.substring( lastDot + 1 );

    this.set(keypath);
    delete this.get(parent)[property];
    return this.update(keypath);
}


// Drag Drop from https://github.com/andyhall/ractive-drag-events/blob/master/ractive-drag-events.js
var eventNames = ['dragstart', 'dragenter', 'dragover', 'dragleave', 'drop', 'dragend']
var niceNames = ['start', 'enter', 'over', 'leave', 'drop', 'end']

function mainHandler(node, fire, event) {
  if (event.type =='dragstart') {
    //console.log(node)
    node.style.background = 'rgba(255,255,255,0)';
    node.getElementsByClassName('img')[0].style.opacity = 0.4;
    //node.getElementsByClassName('img')[0].style.transform = "scale(0.3)";
    setTimeout(function(){
      node.style.opacity = 1;
      node.getElementsByClassName('img')[0].style.opacity = 1;  
    },100)
          // store a ref. on the dragged elem
//          dragged = event.target;
          // make it half transparent
//          event.target.style.opacity = .1;
  }
//  event.target.style.background = "red";
  var type = niceNames[eventNames.indexOf(event.type)]
  fire({
    node: node,
    type: type,
    target: this,
    original: event
  });
}

Ractive.events.dragndrop = function (node, fire) {
  var boundHandler = mainHandler.bind(null, node, fire)

  for (var i = 0; i < eventNames.length; i++) {
    node.addEventListener(eventNames[i], boundHandler)
  }

  return {
    teardown: function () {
      for (var i = 0; i < eventNames.length; i++) {
        node.removeEventListener(eventNames[i], boundHandler)
      }
    }
  }
}


Ractive.prototype.fetch2 = window.fetch2;

Ractive.prototype.focusFirstElement = function(self){
    var self = self || this;
    var focusableElements = self.el.querySelectorAll('button, [href], input:not([type=checkbox]), select, textarea, [tabindex]:not([tabindex="-1"])')
    //console.log('focusFirstElement', self, focusableElements)
    if (!ISMOBILE && focusableElements && focusableElements.length>0) focusableElements[0].focus();
    self.keydownhandler = function(e){
        //console.log(e)
        if (e.target && (e.target.type == "textarea" || e.target.className == "note-editable")) return;
        if (e.keyCode==13){
            var defaultButton = self.el.querySelectorAll('[primary]')[0];
            if (!defaultButton) return true;
            e.stopImmediatePropagation();
            defaultButton.click();
            //console.log('Enter')
            return false;
        }        
    }
    $(self.el).on( "keydown", self.keydownhandler );
    self.on('unrender', function(e){
        //console.log('unrender, unmounting keydown handler')
        $(self.el).off( "keydown", self.keydownhandler );
    })
}

Ractive.defaults.data.formatNumber = function (n, p=2) { return accounting.formatNumber(n,p,' ') }

Ractive.components[ 'Checkbox' ] = Ractive.extend( {
	isolated: true,
	template: '<input type="checkbox" checked="{{value_computed}}" id={{id}} style="{{style}}" class="{{class}}"  disabled="{{readonly}}"  >',
	onrender: function ( options ) {
//	oninit: function ( options ) {
		var self = this;
		//console.dir(options);
		this.observe('checked', function(newValue, oldValue, keypath) {
		//	console.log('check = '+newValue,oldValue );
			//console.log('check = '+newValue);
		});
	},
	data: {checked:null, readonly:false, id:null},
//	data:function(){ return {checked:false}},
	computed: {
		value_computed:  {
	    	get:  function () { 
	    	    var r=this.get( "checked" )==="true" || this.get( "checked" )===true;
	    	    //console.log('Checkbox',r,this.get( "checked" ));
	    	    return r;
	    	    //return this.get( "checked" )==="true" || this.get( "checked" )===true; 
	    	    
	    	},
	        set: function(value){ this.set("checked", value.toString())  }
	    }
	}
});


///////// Select
Ractive.components[ 'Select' ] = Ractive.extend( {
	isolated: true,
	template: 
	'<select value="{{value}}" xx style="{{style}}" class="{{class}}"  size="{{size}}" id="{{id}}" name="{{name}}" {{multiple}}  >'+
	'{{>content}}'+
	'    {{#rows}}'+
	'        <option value="{{.[valuef]}}">{{.[labelf]}}</option>'+
	'    {{/rows}}'+
	'</select>'	,
	oninit: function ( options ) {
		var self = this, url = this.get( 'url' );
	//	this.observe('value', function(newValue, oldValue, keypath) {
	//	    console.log('cb value: '+newValue);
	//	});

		this.observe('url', async function(newValue, oldValue, keypath) {
			var url=newValue;
			if(url && url !=''){
                /*
				$.getJSON(url ,function(data){
					self.set('rows', data.rows).then(function () { 
					//self.set( 'value',0); 
				} );
              });*/
                var [resp,err] = await fetch2(url);
                self.set('rows', resp)

			}
		});

		this.observe('value rows', function(newValue, oldValue, keypath) {
	//		console.log('keypath:'+keypath);
	//		console.log('value to:'+newValue);
    		// var c = newValue;
    		var c = this.get( 'value' ); 
    	//	console.log(c);
            var iid = self.get('valuef') ;
            var obj = self.get('rows');
            if (!Array.isArray(obj)) obj = [];
    		if (obj) obj = obj.filter(function ( o ) { return o[iid] == c; })[0];

    		if(obj !== undefined ) {
    				self.set( "objselected", obj );  
    				//console.log('xsel', obj);
    			    self.set('lbl', obj[self.get('labelf')]);
    				return ;
    		} else {
    				//console.log('xsel ={}');
                self.set('lbl','');
    			self.set( "objselected", {} );  
    		}
		});
        
	  },
	data:function() { return {
		size:1, valuef:"id", labelf:"Naziv", url:null, rows:[], lbl:''
	}}

});

//Ractive.defaults.data.moment = moment;
Ractive.components.Root                    =  require('./components/Root.html');
Ractive.components.f2table                 =  require('./components/f2table.html');
Ractive.components.modal                 =  require('./components/modal.html');
/*
Ractive.components.stanje                  =  require('/components./stanje.html');
Ractive.components.dospece                 =  require('./components/dospece.html');
Ractive.components.izdatiracuni            =  require('/components./izdatiracuni.html');
Ractive.components.zalihe                  =  require('/components./zalihe.html');
*/
//Ractive.components.Grid                    =  require('/components./Grid.html');
//Ractive.components.settings                =  require('/components./settings.html');
//Ractive.components.Companies               =  require('/components./Companies.html');
//Ractive.components.Company                 =  require('/components./Company.html');
Ractive.components.Login                   =  require('./components/Login.html');
//Ractive.components.AddNewCompany           =  require('./components/AddNewCompany.html');
//Ractive.components.FaqCategories           =  require('./components/FaqCategories.html');
//Ractive.components.FaqCategory             =  require('./components/FaqCategory.html');
//Ractive.components.FaqQuestions            =  require('./components/FaqQuestions.html');
//Ractive.components.FaqQuestion             =  require('./components/FaqQuestion.html');
//Ractive.components.Users                   =  require('./components/Users.html');
Ractive.components.TGrid                   =  require('./components/TGrid.html');
Ractive.components.TDetails                =  require('./components/TDetails.html');
Ractive.components.TDetailsSingle          =  require('./components/TDetailsSingle.html');
Ractive.components.ImageBrowser            =  require('./components/ImageBrowser.html');
Ractive.components.Schema                  =  require('./components/Schema.html');
Ractive.components.SchemaNew               =  require('./components/SchemaNew.html');
Ractive.components.SchemaFiledAdd          =  require('./components/SchemaFiledAdd.html');
Ractive.components.map                     =  require('./components/map.html');
//Ractive.components.HtmlEdit                =  require('./components/HtmlEdit.html');
Ractive.components.HtmlEdit                =  require('./components/HtmlEditFrame.html');
GLOBALHtmlEditCounter = 1;
Ractive.components.Selectize               =  require('./components/Selectize.html');
Ractive.components.ShowImage               =  require('./components/ShowImage.html');
Ractive.components.UserList                =  require('./user/UserList.html');
Ractive.components.UserDetail              =  require('./user/UserDetail.html');
Ractive.components.About                   =  require('./components/About.html');
Ractive.components.TGridNested             =  require('./components/TGridNested.html');
Ractive.components.Cropper                  =  require('./components/Cropper.html');
//Ractive.components.Gallery                  =  require('./components/Gallery.html');
Ractive.components.Gallery                  =  require('./components/Gallery2.html');
Ractive.components.Gallery2single           =  require('./components/Gallery2single.html');
Ractive.components.Init           =  require('./components/Init.html');
var package = require('./package.json');
window.version = package.version
//document.addEventListener("deviceready", onDeviceReady, false);
//if (!window.cordova) onDeviceReady()
//async function onDeviceReady() {
    //console.log('onDeviceReady')
//    var localforage = require('localforage')
//    window.localforage = localforage;
//    var db = localforage.createInstance()
//    await db.ready()
//    window.db = db;

/*
    var io = require("socket.io-client");
    var socket = io.connect(HOSTNAME);
    window.socket = socket;

    socket.on('connect', async function(){
        console.log('socket connected');
        if (localStorage.getItem('token')) socket.emit('client_token', localStorage.getItem('token'))
    });    
    
    socket.on('live_path', function(p){
        console.log(p)
        if (ractive) ractive.set('live_path', p)
    })
*/

    var ractive = new Ractive.components.Root({
        el: 'body',
        append: false
    });
    window.ractive = ractive;
    

    document.addEventListener("backbutton", function(){
       /* if (ractive.get('QRScannerActive')){
            ractive.set('QRScannerActive',false)
            //QRScanner.hide()
            QRScanner.destroy()
            return
        } */
        if (ractive.get('selectedModule')){
            ractive.set('selectedModule',null)
        } else navigator.app.exitApp();
    }, false);

//}

history.pushState(null, "SuperAdminV2", "index.html");
window.onpopstate = function(){
	history.pushState(null, "SuperAdminV2", "index.html");
	//console.log('pop');
    var esc = $.Event("keydown", { keyCode: 27 });
    $("body").trigger(esc);
    //if (ractive.get('selectedModule')) ractive.set('selectedModule',null)
}

function keydownhandler(e){
    //console.log('keydownhandler');
    if (e.keyCode==27){
        e.stopImmediatePropagation();
        ractive.set('selectedModule',null)
        return false;
    }
}
//console.log(self.nodes.rmodal);
$('body').on( "keydown", keydownhandler );
