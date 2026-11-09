( function () {
	const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
	const { getSetting } = window.wc.wcSettings;
	const { createElement } = window.wp.element;
	const { decodeEntities } = window.wp.htmlEntities;
	const s = getSetting( 'stripe_travel_globe_data', {} );
	const label = decodeEntities( s.title || 'Stripe' );
	const Content = () => createElement( 'div', null, decodeEntities( s.description || '' ) );
	registerPaymentMethod( {
		name: 'stripe_travel_globe',
		label: createElement( 'span', null, label ),
		content: createElement( Content ),
		edit: createElement( Content ),
		canMakePayment: () => true,
		ariaLabel: label,
		supports: { features: s.supports || [ 'products' ] },
	} );
} )();
