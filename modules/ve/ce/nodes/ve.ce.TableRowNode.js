/**
 * VisualEditor content editable TableRowNodw class.
 *
 * @copyright 2011-2012 VisualEditor Team and others; see AUTHORS.txt
 * @license The MIT License (MIT); see LICENSE.txt
 */

/**
 * ContentEditable node for a table row.
 *
 * @class
 * @constructor
 * @extends {ve.ce.BranchNode}
 * @param model {ve.dm.TableRowNode} Model to observe
 */
ve.ce.TableRowNode = function( model ) {
	// Inheritance
	ve.ce.BranchNode.call( this, 'tableRow', model, $( '<tr></tr>' ) );
};

/* Static Members */

/**
 * Node rules.
 *
 * @see ve.ce.NodeFactory
 * @static
 * @member
 */
ve.ce.TableRowNode.rules = {
	'canBeSplit': false
};

/* Registration */

ve.ce.nodeFactory.register( 'tableRow', ve.ce.TableRowNode );

/* Inheritance */

ve.extendClass( ve.ce.TableRowNode, ve.ce.BranchNode );
