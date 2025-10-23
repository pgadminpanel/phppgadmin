/*----------------------------------------------------------------------------\
|                        XLoadTree 2 PRE RELEASE                              |
|                                                                             |
| This is a pre release and may not be redistributed.                         |
| Watch http://webfx.eae.net for the final version                            |
|                                                                             |
|-----------------------------------------------------------------------------|
|                   Created by Erik Arvidsson & Emil A Eklund                 |
|                  (http://webfx.eae.net/contact.html#erik)                   |
|                  (http://webfx.eae.net/contact.html#emil)                   |
|                      For WebFX (http://webfx.eae.net/)                      |
|-----------------------------------------------------------------------------|
| A tree menu system for IE 5.5+, Mozilla 1.4+, Opera 7.5+                    |
|-----------------------------------------------------------------------------|
|         Copyright (c) 1999 - 2005 Erik Arvidsson & Emil A Eklund            |
|-----------------------------------------------------------------------------|
| This software is provided "as is", without warranty of any kind, express or |
| implied, including  but not limited  to the warranties of  merchantability, |
| fitness for a particular purpose and noninfringement. In no event shall the |
| authors or  copyright  holders be  liable for any claim,  damages or  other |
| liability, whether  in an  action of  contract, tort  or otherwise, arising |
| from,  out of  or in  connection with  the software or  the  use  or  other |
| dealings in the software.                                                   |
| - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - |
| This  software is  available under the  three different licenses  mentioned |
| below.  To use this software you must chose, and qualify, for one of those. |
| - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - |
| The WebFX Non-Commercial License          http://webfx.eae.net/license.html |
| Permits  anyone the right to use the  software in a  non-commercial context |
| free of charge.                                                             |
| - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - |
| The WebFX Commercial license           http://webfx.eae.net/commercial.html |
| Permits the  license holder the right to use  the software in a  commercial |
| context. Such license must be specifically obtained, however it's valid for |
| any number of  implementations of the licensed software.                    |
| - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - |
| GPL - The GNU General Public License    http://www.gnu.org/licenses/gpl.txt |
| Permits anyone the right to use and modify the software without limitations |
| as long as proper  credits are given  and the original  and modified source |
| code are included. Requires  that the final product, software derivate from |
| the original  source or any  software  utilizing a GPL  component, such  as |
| this, is also licensed under the GPL license.                               |
|-----------------------------------------------------------------------------|
| 2004-02-21 | Pre release distributed to a few selected tester               |
| 2005-06-06 | Removed dependency on XML Extras                               |
|-----------------------------------------------------------------------------|
| Dependencies: xtree2.js Supplies the tree control                           |
|-----------------------------------------------------------------------------|
| Created 2003-??-?? | All changes are in the log above. | Updated 2004-06-06 |
|-----------------------------------------------------------------------------|
| Note local changes have been made to allow Icons to have different links    |
|  than their text label counterparts. Thanks to JGuillaume de Rorthais       |
\----------------------------------------------------------------------------*/

webFXTreeConfig.loadingText = "Loading...";
webFXTreeConfig.loadingIcon = "images/loading.gif";
webFXTreeConfig.errorLoadingText = "Error Loading";
webFXTreeConfig.errorIcon = "images/exclamation.16.png";
webFXTreeConfig.reloadText = "Click to reload";

function WebFXLoadTree(
	sText,
	sXmlSrc,
	oAction,
	sBehavior,
	sIcon,
	oIconAction,
	sOpenIcon,
	sSemanticId
) {
	WebFXTree.call(
		this,
		sText,
		oAction,
		sBehavior,
		sIcon,
		oIconAction,
		sOpenIcon,
		sSemanticId
	);

	// setup default property values
	this.src = sXmlSrc;
	this.loading = !sXmlSrc;
	this.loaded = !sXmlSrc;
	this.errorText = "";

	if (this.src) {
		/// add loading Item
		this._loadingItem = WebFXLoadTree.createLoadingItem();
		this.add(this._loadingItem);

		if (this.getExpanded()) {
			WebFXLoadTree.loadXmlDocument(this);
		}
	}
}

WebFXLoadTree.createLoadingItem = function () {
	return new WebFXTreeItem(
		webFXTreeConfig.loadingText,
		null,
		null,
		webFXTreeConfig.loadingIcon
	);
};

_p = WebFXLoadTree.prototype = new WebFXTree();

_p.setExpanded = function (b) {
	WebFXTree.prototype.setExpanded.call(this, b);

	if (this.src && b) {
		if (!this.loaded && !this.loading) {
			// load
			WebFXLoadTree.loadXmlDocument(this);
		}
	}
};

function WebFXLoadTreeItem(
	sText,
	sXmlSrc,
	oAction,
	eParent,
	sIcon,
	oIconAction,
	sOpenIcon,
	sSemanticId
) {
	WebFXTreeItem.call(
		this,
		sText,
		oAction,
		eParent,
		sIcon,
		oIconAction,
		sOpenIcon,
		sSemanticId
	);

	// setup default property values
	this.src = sXmlSrc;
	this.loading = !sXmlSrc;
	this.loaded = !sXmlSrc;
	this.errorText = "";

	if (this.src) {
		/// add loading Item
		this._loadingItem = WebFXLoadTree.createLoadingItem();
		this.add(this._loadingItem);

		if (this.getExpanded()) {
			WebFXLoadTree.loadXmlDocument(this);
		}
	}
}

_p = WebFXLoadTreeItem.prototype = new WebFXTreeItem();

_p.setExpanded = function (b) {
	WebFXTreeItem.prototype.setExpanded.call(this, b);

	if (this.src && b) {
		if (!this.loaded && !this.loading) {
			// load
			WebFXLoadTree.loadXmlDocument(this);
		}
	}
};

// reloads the src file if already loaded
WebFXLoadTree.prototype.reload = _p.reload = function () {
	// if loading do nothing
	if (this.loaded) {
		var t = this.getTree();
		var expanded = this.getExpanded();
		var sr = t.getSuspendRedraw();
		t.setSuspendRedraw(true);

		// remove
		while (this.childNodes.length > 0) {
			this.remove(this.childNodes[this.childNodes.length - 1]);
		}

		this.loaded = false;

		this._loadingItem = WebFXLoadTree.createLoadingItem();
		this.add(this._loadingItem);

		if (expanded) {
			this.setExpanded(true);
		}

		t.setSuspendRedraw(sr);
		this.update();
	} else if (this.open && !this.loading) {
		WebFXLoadTree.loadXmlDocument(this);
	}
};

WebFXLoadTree.prototype.setSrc = _p.setSrc = function (sSrc) {
	var oldSrc = this.src;
	if (sSrc == oldSrc) return;

	var expanded = this.getExpanded();

	// remove all
	this._callSuspended(function () {
		// remove
		while (this.childNodes.length > 0)
			this.remove(this.childNodes[this.childNodes.length - 1]);
	});
	this.update();

	this.loaded = false;
	this.loading = false;
	if (this._loadingItem) {
		this._loadingItem.dispose();
		this._loadingItem = null;
	}
	this.src = sSrc;

	if (sSrc) {
		this._loadingItem = WebFXLoadTree.createLoadingItem();
		this.add(this._loadingItem);
	}

	this.setExpanded(expanded);
};

WebFXLoadTree.prototype.getSrc = _p.getSrc = function () {
	return this.src;
};

WebFXLoadTree.prototype.dispose = function () {
	// Clean up from handler registry first
	webFXTreeHandler.removeNode(this);
	WebFXTree.prototype.dispose.call(this);
	if (this._xmlHttp) {
		if (this._xmlHttp.dispose) {
			this._xmlHttp.dispose();
		}
		try {
			this._xmlHttp.onreadystatechange = null;
			this._xmlHttp.abort();
		} catch (ex) {}
		this._xmlHttp = null;
	}
};

_p.dispose = function () {
	// Clean up from handler registry first
	webFXTreeHandler.removeNode(this);
	WebFXTreeItem.prototype.dispose.call(this);
	if (this._xmlHttp) {
		if (this._xmlHttp.dispose) {
			this._xmlHttp.dispose();
		}
		try {
			this._xmlHttp.onreadystatechange = null;
			this._xmlHttp.abort();
		} catch (ex) {}
		this._xmlHttp = null;
	}
};

// The path is divided by '/' and the item is identified by the text
WebFXLoadTree.prototype.openPath = _p.openPath = function (
	sPath,
	bSelect,
	bFocus
) {
	// remove any old pending paths to open
	delete this._pathToOpen;
	//delete this._pathToOpenById;
	this._selectPathOnLoad = bSelect;
	this._focusPathOnLoad = bFocus;

	if (sPath == "") {
		if (bSelect) {
			this.select();
		}
		if (bFocus) {
			window.setTimeout(
				'WebFXTreeAbstractNode._onTimeoutFocus("' + this.getId() + '")',
				10
			);
		}
		return;
	}

	var parts = sPath.split("/");
	var remainingPath = parts.slice(1).join("/");

	if (sPath.charAt(0) == "/") {
		this.getTree().openPath(remainingPath, bSelect, bFocus);
	} else {
		// open
		this.setExpanded(true);
		if (this.loaded) {
			parts = sPath.split("/");
			var ti = this.findChildByText(parts[0]);
			if (!ti) {
				throw 'Could not find child node with text "' + parts[0] + '"';
			}

			ti.openPath(remainingPath, bSelect, bFocus);
		} else {
			this._pathToOpen = sPath;
		}
	}
};

// Opera has some serious attribute problems. We need to use getAttribute
// for certain attributes
WebFXLoadTree._attrs = ["text", "src", "action", "id", "target", "semanticid"];

WebFXLoadTree.createItemFromElement = function (oNode) {
	var jsAttrs = {};
	var i, l;

	l = oNode.attributes.length;
	for (i = 0; i < l; i++) {
		oNode.attributes[i].nodeValue = String(
			oNode.attributes[i].nodeValue
		).replace(/&#38;/g, "&"); // replace for Safari fix for DOM Bug
		if (oNode.attributes[i] == null) {
			continue;
		}
		jsAttrs[oNode.attributes[i].nodeName] = oNode.attributes[i].nodeValue;
	}

	var name, val;
	for (i = 0; i < WebFXLoadTree._attrs.length; i++) {
		name = WebFXLoadTree._attrs[i];
		value = oNode.getAttribute(name);
		if (value) {
			jsAttrs[name] = value;
		}
	}

	var action;
	if (jsAttrs.onaction) {
		action = new Function(jsAttrs.onaction);
	} else if (jsAttrs.action) {
		action = jsAttrs.action;
	}
	// Pass semantic ID to constructor to ensure it's used for ID generation
	var jsNode = new WebFXLoadTreeItem(
		jsAttrs.html || "",
		jsAttrs.src,
		action,
		null,
		jsAttrs.icon,
		jsAttrs.iconaction,
		jsAttrs.openicon,
		jsAttrs.semanticid // Pass semantic ID as constructor parameter
	);
	if (jsAttrs.text) {
		jsNode.setText(jsAttrs.text);
	}

	if (jsAttrs.target) {
		jsNode.target = jsAttrs.target;
	}
	// After construction, if we have a semantic ID, regenerate the node ID to ensure it's properly registered
	// NOTE: Do not override jsNode.id from XML attributes.
	// IDs are generated client-side from the full ancestor path to guarantee uniqueness.
	// We keep semanticid/id in jsNode.attributes for compatibility if needed.
	if (jsAttrs.tooltip) {
		jsNode.toolTip = jsAttrs.tooltip;
	}
	if (jsAttrs.expanded) {
		jsNode.setExpanded(jsAttrs.expanded != "false");
	}
	if (jsAttrs.onload) {
		jsNode.onload = new Function(jsAttrs.onload);
	}
	if (jsAttrs.onerror) {
		jsNode.onerror = new Function(jsAttrs.onerror);
	}

	jsNode.attributes = jsAttrs;

	// go through childNodes
	var cs = oNode.childNodes;
	l = cs.length;
	for (i = 0; i < l; i++) {
		if (cs[i].tagName == "tree") {
			jsNode.add(WebFXLoadTree.createItemFromElement(cs[i]));
		}
	}

	return jsNode;
};

WebFXLoadTree.loadXmlDocument = function (jsNode) {
	if (jsNode.loading || jsNode.loaded) {
		return;
	}
	jsNode.loading = true;
	var id = jsNode.getId();
	var nodeRef = jsNode; // capture direct reference for async callbacks

	// Use modern fetch() API instead of XMLHttpRequest
	// This avoids the complex queue management and concurrent request issues
	fetch(jsNode.src, {
		method: "GET",
		headers: {
			Accept: "application/xml, text/xml",
		},
	})
		.then(function (response) {
			if (!response.ok) {
				throw new Error("HTTP " + response.status);
			}
			return response.text();
		})
		.then(function (xmlText) {
			var n = nodeRef;
			if (!n) return;

			// Parse XML from response text
			var parser = new DOMParser();
			var doc = parser.parseFromString(xmlText, "application/xml");

			// Check for parse errors
			if (doc.getElementsByTagName("parsererror").length > 0) {
				throw new Error("XML Parse Error");
			}

			// Simulate the old _xmlHttp object for compatibility
			n._xmlHttp = {
				responseXML: doc,
				readyState: 4,
			};

			WebFXLoadTree.documentLoaded(n);
		})
		.catch(function (error) {
			var n = nodeRef;
			if (!n) return;

			n.loading = false;
			n.loaded = true;
			n.errorText =
				webFXTreeConfig.errorLoadingText +
				" " +
				n.src +
				" (" +
				error.message +
				")";

			var t = n.getTree();
			var oldSuspend = t.getSuspendRedraw();
			t.setSuspendRedraw(true);

			n._loadingItem.icon = webFXTreeConfig.errorIcon;
			n._loadingItem.text = n.errorText;
			n._loadingItem.action = WebFXLoadTree._reloadParent;
			n._loadingItem.toolTip = webFXTreeConfig.reloadText;

			t.setSuspendRedraw(oldSuspend);
			n._loadingItem.update();

			if (typeof n.onerror == "function") {
				n.onerror();
			}
		});
};

WebFXLoadTree._onload = function (sId) {
	// Deprecated: This function is no longer used with fetch() API
	// Kept for backward compatibility if needed
};

WebFXLoadTree._ontimeout = function (sId) {
	// Deprecated: This function is no longer used with fetch() API
	// Kept for backward compatibility if needed
};

// Inserts an xml document as a subtree to the provided node
WebFXLoadTree.documentLoaded = function (jsNode) {
	if (jsNode.loaded) {
		return;
	}

	jsNode.errorText = "";
	jsNode.loaded = true;
	jsNode.loading = false;

	var t = jsNode.getTree();
	var oldSuspend = t.getSuspendRedraw();
	t.setSuspendRedraw(true);

	var doc = jsNode._xmlHttp.responseXML;

	// check that the load of the xml file went well
	if (
		!doc ||
		(doc.parserError && doc.parseError.errorCode != 0) ||
		!doc.documentElement
	) {
		if (!doc || doc.parseError.errorCode == 0) {
			jsNode.errorText =
				webFXTreeConfig.errorLoadingText +
				" " +
				jsNode.src +
				" (" +
				jsNode._xmlHttp.status +
				": " +
				jsNode._xmlHttp.statusText +
				")";
		} else {
			jsNode.errorText =
				webFXTreeConfig.errorLoadingText +
				" " +
				jsNode.src +
				" (" +
				doc.parseError.reason +
				")";
		}
	} else {
		// there is one extra level of tree elements
		var root = doc.documentElement;

		// loop through all tree children
		var count = 0;
		var cs = root.childNodes;
		var l = cs.length;
		var newNode;
		for (var i = 0; i < l; i++) {
			if (cs[i].tagName == "tree") {
				newNode = WebFXLoadTree.createItemFromElement(cs[i]);
				jsNode.add(newNode);
				count++;
			}
		}

		if (count == 1 && newNode.childNodes.length) {
			var parent = jsNode.parentNode;
			newNode.setExpanded(true);
		}
		// if no children we got an error
		if (count == 0) {
			jsNode.errorText =
				webFXTreeConfig.errorLoadingText + " " + jsNode.src + " (???)";
		}
	}

	if (jsNode.errorText != "") {
		jsNode._loadingItem.icon = webFXTreeConfig.errorIcon;
		jsNode._loadingItem.text = jsNode.errorText;
		jsNode._loadingItem.action = WebFXLoadTree._reloadParent;
		jsNode._loadingItem.toolTip = webFXTreeConfig.reloadText;

		t.setSuspendRedraw(oldSuspend);

		jsNode._loadingItem.update();

		if (typeof jsNode.onerror == "function") {
			jsNode.onerror();
		}
	} else {
		// remove dummy
		if (jsNode._loadingItem != null) {
			jsNode.remove(jsNode._loadingItem);
		}

		if (jsNode._pathToOpen) {
			jsNode.openPath(
				jsNode._pathToOpen,
				jsNode._selectPathOnLoad,
				jsNode._focusPathOnLoad
			);
		}

		t.setSuspendRedraw(oldSuspend);
		jsNode.update();
		if (typeof jsNode.onload == "function") {
			jsNode.onload();
		}
	}
};

WebFXLoadTree._reloadParent = function () {
	this.getParent().reload();
};

var webFXLoadTreeQueue = {
	// Deprecated: Queue is no longer needed with fetch() API
	// fetch() handles concurrent requests natively
	_nodes: [],
	add: function (jsNode) {
		// No-op: fetch handles this automatically
	},
	remove: function (jsNode) {
		// No-op: fetch handles this automatically
	},
	_send: function () {
		// No-op: fetch handles this automatically
	},
};
