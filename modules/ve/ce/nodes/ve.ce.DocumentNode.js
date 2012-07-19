/**
 * VisualEditor content editable DocumentNode class.
 *
 * @copyright 2011-2012 VisualEditor Team and others; see AUTHORS.txt
 * @license The MIT License (MIT); see LICENSE.txt
 */

/**
 * ContentEditable node for a document.
 *
 * @class
 * @constructor
 * @extends {ve.ce.BranchNode}
 * @param model {ve.dm.DocumentNode} Model to observe
 */
ve.ce.DocumentNode = function( model, surface ) {
	// Inheritance
	ve.ce.BranchNode.call( this, 'document', model );

	// Properties
	this.surface = surface;

	// DOM Changes
	this.$.addClass( 've-ce-documentNode' );
	this.$.attr('contentEditable', 'true');
	this.$.attr('spellcheck', 'true');
};

/* Static Members */

/**
 * Node rules.
 *
 * @see ve.ce.NodeFactory
 * @static
 * @member
 */
ve.ce.DocumentNode.rules = {
	'canBeSplit': false
};

/* Methods */

/**
 * Gets the outer length, which for a document node is the same as the inner length.
 *
 * @method
 * @returns {Integer} Length of the entire node
 */
ve.ce.DocumentNode.prototype.getOuterLength = function() {
	return this.length;
};

/**
 * Gets the surface this document is attached to.
 *
 * @method
 * @returns {ve.ve.Surface} Surface this document is attached to
 */
ve.ce.DocumentNode.prototype.getSurface = function() {
	return this.surface;
};

/* Registration */

ve.ce.nodeFactory.register( 'document', ve.ce.DocumentNode );

/* Inheritance */

ve.extendClass( ve.ce.DocumentNode, ve.ce.BranchNode );
