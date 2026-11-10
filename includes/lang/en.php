<?php
/**
 * English language knowledge for the search assistant. Same structure as es.php.
 *
 * @package AsesorInteligente
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'ASI_CLI_TESTS' ) ) {
	exit;
}

return array(

	'code' => 'en',

	'stopwords' => array(
		'a', 'an', 'the', 'and', 'or', 'but', 'of', 'to', 'in', 'on', 'at', 'for', 'from', 'with', 'without', 'by', 'about', 'into', 'over', 'under',
		'is', 'are', 'was', 'were', 'be', 'been', 'am', 'it', 'its', 'this', 'that', 'these', 'those', 'there', 'here', 'i', 'me', 'my', 'we', 'our',
		'you', 'your', 'they', 'them', 'their', 'he', 'she', 'his', 'her', 'what', 'which', 'who', 'where', 'when', 'how', 'why', 'some', 'any', 'all',
		'each', 'other', 'others', 'more', 'most', 'very', 'so', 'too', 'also', 'just', 'only', 'if', 'then', 'than', 'as', 'do', 'does', 'did', 'can',
		'could', 'would', 'should', 'will', 'please', 'pls', 'thanks', 'thank', 'hi', 'hello', 'kind', 'kinds', 'type', 'types', 'thing', 'things',
	),

	'fillers' => array(
		'want', 'wanna', 'looking', 'look', 'search', 'searching', 'need', 'needs', 'have', 'has', 'sell', 'sells', 'offer', 'offers', 'show', 'see',
		'find', 'get', 'buy', 'purchase', 'like', 'interested', 'know', 'information', 'info', 'available', 'option', 'options', 'product', 'products',
		'help', 'give', 'got', 'quote', 'price', 'prices', 'cost', 'costs', 'much', 'now', 'instead',
	),

	'price_words' => array( 'price', 'prices', 'how much', 'cost', 'costs', 'pricing', 'rate' ),

	// Customer amounts (ASI_Money::extract_budget). Lowercase, no accents.
	'profile_chips' => array( 'We are 4 with kids', 'It is for work', 'For the city', 'For the farm' ),

	'similar_markers' => array( 'similar to', 'similar to the', 'like the', 'something like', 'alternative to', 'alternatives to', 'instead of', 'instead of the', 'comparable to', 'competitor of', 'compared to' ),
	'similar_strong'  => array( 'similar', 'alternative', 'instead', 'comparable', 'competitor' ),
	'similar_stops'   => array( 'that', 'but', 'and', 'or', 'for', 'with', 'what', 'which', 'do', 'you', 'recommend', 'cheaper', 'under', 'in', 'of' ),
	'similar_chips'      => array( 'Compare them', 'Cheaper', 'Monthly payments' ),
	'similar_body_chips' => array( 'It is a pickup', 'It is an SUV', 'It is a sedan' ),

	'quote_words'       => array( 'i am interested', 'im interested', 'i want it', 'quote', 'get a quote', 'send me the quote', 'pdf', 'spec sheet' ),
	'compare_pdf_words' => array( 'comparison pdf', 'pdf comparison', 'send me the comparison', 'email the comparison' ),
	'finance_words'     => array( 'monthly', 'payment', 'payments', 'financing', 'finance', 'down payment', 'loan', 'per month' ),
	'yes_words'         => array( 'yes', 'yeah', 'sure', 'ok', 'okay', 'please', 'yes please', 'go ahead', 'sounds good' ),
	'cancel_words'      => array( 'cancel', 'never mind', 'no thanks', 'later' ),
	'which_chips'       => array( 'The first one', 'The second one', 'The third one' ),
	'after_quote_chips' => array( 'Monthly payments', 'See other options' ),
	'finance_chips'     => array( 'Yes, send it', '30% down', '60 months' ),

	'target_fillers'    => array( 'i told you', 'of these', 'i want', 'i would like', 'please', 'the quote for', 'quotes for', 'also' ),

	'money_words' => array(
		'max'          => array( 'under', 'less than', 'below', 'up to', 'max', 'maximum', 'no more than', 'at most', 'not over', 'cheaper than' ),
		'min'          => array( 'over', 'more than', 'above', 'at least', 'minimum', 'starting at' ),
		'between'      => array( 'between', 'from' ),
		'between_join' => array( 'and', 'to' ),
		'budget'       => array( 'budget', 'my budget', 'i have', 'i can spend', 'i can pay', 'i want to spend', 'spend' ),
		'around'       => array( 'around', 'about', 'roughly', 'approximately', 'approx', 'close to' ),
	),

	'price_max_patterns' => array(
		'/(?:less than|under|below|up to|max|maximum|no more than|cheaper than)\s*(?:\$|usd|q|l)?\s*([0-9][0-9.,]*\s*k?)/',
	),
	'price_min_patterns' => array(
		'/(?:more than|over|above|from|min|minimum|at least)\s*(?:\$|usd|q|l)?\s*([0-9][0-9.,]*\s*k?)/',
	),

	'synonyms' => array(
		array( 'pickup', 'pick up', 'pickup truck' ),
		array( 'spare part', 'part', 'replacement', 'component' ),
		array( 'used', 'pre owned', 'preowned', 'second hand', 'secondhand' ),
		array( 'truck', 'lorry', 'pickup' ),
		array( 'car', 'auto', 'automobile', 'vehicle' ),
		array( 'forklift', 'lift truck', 'fork truck' ),
		array( 'computer', 'laptop', 'notebook', 'pc' ),
		array( 'cellphone', 'cell phone', 'mobile phone', 'smartphone', 'phone' ),
		array( 'tire', 'tyre' ),
		array( 'oil', 'lubricant' ),
		array( 'fertilizer', 'fertiliser' ),
		array( 'seed', 'seeds' ),
		array( 'pump', 'water pump' ),
		array( 'generator', 'genset', 'power plant' ),
		array( 'excavator', 'digger', 'backhoe' ),
		array( 'irrigation', 'watering', 'sprinkler' ),
		array( 'electric', 'battery' ),
		array( 'diesel', 'gasoil' ),
		array( 'gasoline', 'gas', 'petrol' ),
		array( 'cheap', 'affordable', 'budget', 'inexpensive' ),
		array( 'rent', 'rental', 'hire', 'lease', 'leasing' ),
		array( 'sale', 'sales', 'buy', 'purchase' ),
		array( 'maintenance', 'repair', 'service', 'workshop' ),
		array( 'training', 'course', 'workshop' ),
		array( 'shirt', 't shirt', 'tee' ),
		array( 'shoe', 'footwear', 'sneaker' ),
		array( 'man', 'men', 'male', 'masculine', 'gentlemen' ),
		array( 'woman', 'women', 'female', 'ladies', 'lady' ),
		array( 'kid', 'kids', 'child', 'children', 'boy', 'girl' ),
		array( 'baby', 'babies', 'infant' ),
	),

	'greetings' => array( 'hi', 'hello', 'hey', 'good morning', 'good afternoon', 'good evening', 'greetings', 'howdy' ),

	'more' => array( 'more', 'more options', 'see more', 'show more', 'show me more', 'others', 'other options', 'next', 'what else', 'anything else', 'more results', 'keep going' ),

	'ordinals' => array(
		'first' => 1, '1st' => 1, '1' => 1, 'second' => 2, '2nd' => 2, '2' => 2, 'third' => 3, '3rd' => 3, '3' => 3, 'fourth' => 4, '4th' => 4, '4' => 4,
		'fifth' => 5, '5th' => 5, '5' => 5, 'sixth' => 6, '6' => 6, 'seventh' => 7, '7' => 7, 'eighth' => 8, '8' => 8, 'last' => -1,
	),
	'reference_markers' => array( 'the', 'that', 'this', 'number', 'option', 'about the', 'more about the', 'details of the' ),
	'reference_alone'   => array( 'that one', 'this one', 'that', 'tell me more', 'more details', 'details', 'i like that one', 'i am interested in that one' ),

	'list_words'  => array( 'all', 'list', 'which', 'what areas', 'areas', 'every' ),
	'place_words' => array( 'store', 'stores', 'branch', 'branches', 'location', 'locations', 'office', 'offices', 'shop', 'shops', 'point', 'pickup', 'address', 'area', 'areas', 'hours', 'open', 'near', 'map', 'located' ),

	'refine_markers' => array( 'and', 'only', 'just', 'but', 'in', 'with', 'without', 'brand', 'color', 'colour', 'that are' ),

	'negation_markers' => array( 'that is not', 'that are not', 'that isnt', 'that arent', 'not the', 'except', 'excluding', 'other than', 'but not', 'i dont want' ),

	'evidence' => array(
		'hours'     => array( 'hour', 'hours', 'open', 'opening', 'close', 'closed', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday', 'am', 'pm', 'holiday' ),
		'contact'   => array( 'phone', 'call', 'whatsapp', 'email', 'mail', 'write', 'contact', 'customer', 'number' ),
		'location'  => array( 'address', 'located', 'street', 'avenue', 'zone', 'km', 'boulevard', 'mall', 'building', 'road', 'suite' ),
		'about'     => array( 'we', 'company', 'mission', 'vision', 'values', 'history', 'founded', 'years', 'experience', 'leader', 'leading', 'commitment', 'offer' ),
		'quote'     => array( 'quote', 'form', 'advisor', 'request', 'price' ),
		'financing' => array( 'financing', 'credit', 'installment', 'term', 'rate', 'leasing', 'bank' ),
		'jobs'      => array( 'job', 'jobs', 'vacancy', 'cv', 'resume', 'position', 'career', 'talent' ),
		'human'     => array( 'advisor', 'phone', 'whatsapp', 'email', 'call' ),
	),

	'fact_words' => array(
		'price' => array( 'price' ), 'cost' => array( 'price' ), 'costs' => array( 'price' ),
		'brand' => array( 'brand', 'manufacturer' ), 'manufacturer' => array( 'brand', 'manufacturer' ), 'makes' => array( 'brand', 'manufacturer' ),
		'code' => array( 'code', 'sku', 'reference' ), 'sku' => array( 'code', 'sku' ), 'model' => array( 'model', 'code' ), 'category' => array( 'category', 'line' ),
		'weigh' => array( 'weight' ), 'weighs' => array( 'weight' ), 'weight' => array( 'weight' ), 'heavy' => array( 'weight' ),
		'size' => array( 'size', 'dimension', 'measure' ), 'big' => array( 'size', 'dimension' ), 'dimensions' => array( 'dimension', 'size' ), 'tall' => array( 'height' ), 'height' => array( 'height' ), 'wide' => array( 'width' ), 'width' => array( 'width' ), 'long' => array( 'length' ), 'length' => array( 'length' ),
		'color' => array( 'color', 'colour' ), 'colors' => array( 'color', 'colour' ), 'material' => array( 'material' ), 'made' => array( 'material', 'origin' ),
		'warranty' => array( 'warranty', 'guarantee' ), 'guarantee' => array( 'warranty', 'guarantee' ), 'capacity' => array( 'capacity', 'load' ), 'load' => array( 'capacity', 'load' ),
		'power' => array( 'power', 'hp', 'horsepower' ), 'horsepower' => array( 'power', 'hp', 'horsepower' ), 'hp' => array( 'power', 'hp' ), 'engine' => array( 'engine', 'motor' ),
		'consumption' => array( 'consumption' ), 'battery' => array( 'battery' ), 'last' => array( 'duration', 'battery', 'warranty' ), 'speed' => array( 'speed' ), 'voltage' => array( 'voltage' ),
		'fuel' => array( 'fuel' ), 'transmission' => array( 'transmission' ), 'year' => array( 'year', 'model' ), 'origin' => array( 'origin', 'country' ), 'ingredients' => array( 'ingredient' ), 'scent' => array( 'scent', 'fragrance' ),
	),
	'yes_no_verbs'   => array( 'does it have', 'does it come with', 'does it include', 'has', 'have', 'include', 'includes', 'come with', 'comes with', 'is it', 'work with', 'use' ),
	'question_words' => array( 'how', 'much', 'many', 'what', 'which', 'is', 'are', 'does', 'do', 'it', 'its', 'the', 'tell', 'me', 'know' ),

	'compare_markers'   => array( 'difference between', 'differences between', 'compare', 'compare it', 'compare this', 'comparison', 'vs', 'versus', 'which is better', 'what is better' ),
	'compare_splitters' => array( 'and', 'or', 'with', 'vs', 'versus', 'against', 'to' ),
	'compare_both'      => array( 'compare them', 'the difference', 'which is better', 'which one is better' ),

	'cheapest_markers' => array( 'cheapest', 'most affordable', 'lowest price', 'least expensive', 'cheaper' ),
	'priciest_markers' => array( 'most expensive', 'highest price', 'priciest' ),

	'usted' => array(),

	// Questions about the site itself ("what is {site name}?"): they go to the about page. See es.php.
	'about_site_words' => array( 'what', 'who', 'is', 'are', 'was', 'tell', 'me', 'us', 'about', 'info', 'information', 'more', 'know', 'little', 'bit', 'history' ),
	'company_words'    => array( 'group', 'grupo', 'store', 'stores', 'company', 'corporation', 'corp', 'inc', 'llc', 'ltd', 'co', 'and' ),

	'intents' => array(
		'greeting' => array(
			'anchors'  => array( 'hi', 'hello', 'hey', 'good morning', 'good afternoon', 'good evening', 'greetings', 'howdy' ),
			'training' => array( 'hi', 'hello there', 'good morning', 'hey how are you', 'good afternoon' ),
		),
		'thanks' => array(
			'anchors'  => array( 'thanks', 'thank you', 'thx', 'appreciate it', 'much appreciated', 'great thanks' ),
			'training' => array( 'thanks', 'thank you very much', 'ok thanks', 'great thank you', 'appreciate it' ),
		),
		'goodbye' => array(
			'anchors'  => array( 'bye', 'goodbye', 'see you', 'see ya', 'that is all', 'thats all', 'later' ),
			'training' => array( 'bye', 'goodbye', 'see you later', 'that is all thanks' ),
		),
		'help' => array(
			'anchors'  => array( 'help', 'what can you do', 'how does this work', 'who are you', 'what are you', 'how do i search' ),
			'training' => array( 'help', 'what can you do', 'how does this work', 'who are you', 'how do i search' ),
		),
		'contact' => array(
			'anchors'  => array( 'contact', 'phone', 'phone number', 'call you', 'call', 'email', 'e mail', 'whatsapp', 'reach you', 'get in touch' ),
			'keywords' => array( 'contact', 'contact us', 'contacto' ),
			'training' => array( 'how can i contact you', 'what is your phone number', 'whatsapp number', 'email address', 'get in touch' ),
		),
		'location' => array(
			'anchors'  => array( 'where are you', 'where is', 'location', 'locations', 'address', 'branch', 'branches', 'store', 'stores', 'store in', 'office', 'offices', 'map', 'directions', 'near me', 'area', 'areas', 'pickup point' ),
			'keywords' => array( 'locations', 'branches', 'stores', 'offices', 'find us', 'contact' ),
			'training' => array( 'where are you located', 'store address', 'do you have branches', 'office location', 'how do i get there' ),
		),
		'hours' => array(
			'anchors'  => array( 'hours', 'opening hours', 'business hours', 'what time', 'open', 'close', 'closing' ),
			'keywords' => array( 'hours', 'opening hours', 'locations', 'contact' ),
			'training' => array( 'what are your hours', 'what time do you open', 'are you open on saturday', 'business hours' ),
		),
		'about' => array(
			'anchors'  => array( 'about you', 'about us', 'who are you guys', 'company', 'history', 'what do you do', 'your business', 'mission', 'mission and vision', 'your vision', 'your values', 'who is behind' ),
			'keywords' => array( 'about us', 'about', 'who we are', 'mission', 'company', 'history' ),
			'training' => array( 'tell me about the company', 'what do you do', 'company history', 'who are you guys', 'what is your mission', 'mission and vision' ),
		),
		'quote' => array(
			'anchors'  => array( 'quote', 'quotation', 'estimate', 'proposal' ),
			'keywords' => array( 'quote', 'request a quote', 'quotation', 'contact' ),
			'training' => array( 'i want a quote', 'can you send me a quote', 'request quotation', 'price estimate' ),
		),
		'financing' => array(
			'anchors'  => array( 'financing', 'finance', 'credit', 'loan', 'installments', 'leasing', 'payment plan' ),
			'keywords' => array( 'financing', 'finance', 'credit', 'leasing' ),
			'training' => array( 'do you offer financing', 'can i pay in installments', 'credit options', 'leasing' ),
		),
		'jobs' => array(
			'anchors'  => array( 'job', 'jobs', 'career', 'careers', 'vacancy', 'vacancies', 'hiring', 'work with you', 'resume', 'cv' ),
			'keywords' => array( 'careers', 'jobs', 'work with us', 'vacancies' ),
			'training' => array( 'are you hiring', 'job openings', 'i want to work with you', 'where do i send my resume' ),
		),
		'human' => array(
			'anchors'  => array( 'agent', 'human', 'a person', 'real person', 'sales rep', 'representative', 'advisor', 'salesperson' ),
			'keywords' => array( 'contact', 'contact us', 'sales' ),
			'training' => array( 'talk to a person', 'i want to speak with an agent', 'sales representative', 'human please' ),
		),
	),

	'words' => array(
		'option'    => 'option',
		'options'   => 'options',
		'and'       => 'and',
		'morning'   => 'Good morning',
		'afternoon' => 'Good afternoon',
		'evening'   => 'Good evening',
		'in'        => 'in',
		'more_chip' => 'Show more options',
		'or'        => 'or',
		'price'     => 'Price',
		'brand'     => 'Brand',
		'category'  => 'Category',
		'code'      => 'Code',
		'versus'    => 'vs',
		'among'     => 'among',
		'fact_of'   => 'of',
	),

	'responses' => array(
		'greeting' => array(
			'{saludo}! I am the {site} assistant. What are you looking for today?',
			'Hi there! Tell me what you need and I will help you find it.',
			'{saludo}! Looking for a product or some information?',
			'Hello, great to see you! Just describe what you are looking for in your own words.',
		),
		'greeting_prefix' => array( 'Hi!', '{saludo}!', 'Hello!', 'Hi there!' ),
		'thanks' => array(
			'You are very welcome! Anything else I can help with?',
			'Happy to help! I am here if you need anything else.',
			'Anytime! Let me know if you want to keep looking.',
		),
		'goodbye' => array(
			'See you soon! Have a great day.',
			'Thanks for stopping by! I am here whenever you need me.',
			'Bye! It was a pleasure helping you.',
		),
		'help' => array(
			'I can help you find products, services and information from {site}{en_pais}. Just type what you need in your own words, for example: {facets}.',
			'I am the {site} assistant. I search the whole site{en_pais} for you: products, services, contact details, locations and more. Try something like {facets}.',
		),
		'results' => array(
			'Sure! I found {n} for {label}{en_pais}.',
			'Here are {n} for {label}{en_pais}.',
			'Good news: there are {n} for {label}{en_pais}.',
			'These {n} match what you are looking for.',
			'I found {n} related to {label}.',
		),
		'advice_open' => array(
			'For {summary} I would recommend {options}.',
			'Thinking about {summary}, your best fit is {options}.',
		),
		'advice_open_generic' => array(
			'I would recommend {options}.',
		),
		'advice_in_budget' => array(
			'They all fit your budget {budget}.',
		),
		'advice_over_budget' => array(
			'To be honest, with a budget {budget} what I recommend is a bit high: it starts at {min}. Here are the most affordable ones; if you can stretch your budget a little, they are your best option. Or should I look for something simpler within your budget?',
		),
		'advice_closing' => array(
			'Do you like any of them? I can give you more details, compare them or work out the monthly payments.',
		),
		'ask_budget' => array(
			'Roughly what is your budget? That way I can show you what fits best.',
		),
		'ask_profile' => array(
			'Happy to help you choose! Tell me a bit: how many people will usually ride and what will you use it for (city, highway, work, farm)?',
		),
		'advice_none' => array(
			'For {summary} I do not have anything that fits everything right now.',
		),
		'advice_none_followup' => array(
			'Our advisors can let you know when something like that comes in. Want the contact info?',
		),
		'budget_chip' => array( 'Up to {max}' ),
		'similar_ref_traits' => array( 'The {ref} is {describe}; it stands out for {traits}.' ),
		'similar_ref'        => array( 'The {ref} is {describe}.' ),
		'similar_reco_why'   => array( 'If you want something similar, I would recommend {options}: they are also {why}.' ),
		'similar_reco'       => array( 'If you want something similar, I would recommend {options}.' ),
		'similar_prices'     => array( 'Prices: {prices}.' ),
		'similar_price'      => array( '{title} from {price}' ),
		'similar_no_price'   => array( 'price on request' ),
		'similar_have_ref'   => array( 'And if you like the {ref}, we have it too, from {price}.' ),
		'similar_none'       => array( 'Right now I do not have anything else similar to the {ref}.' ),
		'similar_closing'    => array( 'Want me to compare them side by side?' ),
		'similar_unknown'    => array( 'I do not know the “{ref}” well. What kind of vehicle is it: pickup, SUV or sedan?' ),
		'action_ask_quote'   => array( 'Happy to prepare the quote for the {title}! I just need your name and email (and a phone number if you want an advisor to call you).' ),
		'action_ask_compare' => array( 'I will prepare the comparison of {titles} as a PDF. Could you share your name and email?' ),
		'action_consent'     => array( 'With this an advisor from {site} may contact you. Type “cancel” if you prefer not to continue.' ),
		'action_ask_email'   => array( 'Thanks, {name}. Which email should I send it to?' ),
		'action_ask_name'    => array( 'Whose name should the quote be under?' ),
		'action_which'       => array( 'Which one are you interested in?' ),
		'action_cancelled'   => array( 'No problem, we can pick it up later.' ),
		'action_done_quote'  => array( 'Done, {name}! Here is the quote for the {title}.' ),
		'action_done_compare' => array( 'Done, {name}! Here is the comparison of {titles}.' ),
		'action_emailed'     => array( 'I also sent it to {email}.' ),
		'action_not_emailed' => array( 'I could not email it right now, but you can download it here.' ),
		'action_test_note'   => array( '(Test mode: the sales notification only went to the test address.)' ),
		'action_failed'      => array( 'I could not generate the document right now. You can request it from the vehicle page:' ),
		'action_limit'       => array( 'I have generated several documents in a row; give me a few minutes or request it from the vehicle page:' ),
		'action_after_quote' => array( 'Want me to work out the monthly payments or show you similar options?' ),
		'action_after_compare' => array( 'Can I help you decide, or prepare a quote for one of them?' ),
		'compare_offer_pdf'  => array( 'Want me to email you this comparison as a PDF?' ),
		'file_label_quote'   => array( 'Quote (PDF)' ),
		'file_label_compare' => array( 'Comparison (PDF)' ),
		'file_open'          => array( 'View' ),
		'file_download'      => array( 'Download' ),
		'finance_table'      => array( 'For the {title} ({price}), with a {down} down payment ({pct}), these are the approximate monthly payments:' ),
		'finance_single'     => array( 'For the {title} ({price}), with a {down} down payment ({pct}), the approximate payment over {term} is {payment}.' ),
		'finance_term'       => array( '{n} months' ),
		'finance_head_term'  => array( 'Term' ),
		'finance_head_payment' => array( 'Monthly payment' ),
		'finance_down_min'   => array( 'The minimum down payment is {pct}, so I used {down}.' ),
		'finance_down_max'   => array( 'The maximum down payment is {pct}, so I used {down}.' ),
		'finance_offer_quote' => array( 'Reference rate {rate} per year; an advisor confirms the final figure. Shall I prepare the quote as a PDF and email it to you?' ),
		'finance_disclaimer' => array( 'Reference rate {rate} per year; an advisor confirms the final figure.' ),
		'action_ask_quote_many' => array( 'Happy to prepare the quote for {titles}! I just need your name and email (and a phone number if you want an advisor to call you).' ),
		'action_done_quote_many' => array( 'Done, {name}! Here is the quote for {titles}.' ),
		'results_budget' => array(
			'With a budget {budget} I have {n}. The ones that make the most of your budget come first.',
			'For a budget {budget} there are {n}; I start with the most complete within that amount.',
		),
		'results_budget_attrs' => array(
			'With a budget {budget} I have {n} {wanted}. The ones that make the most of your budget come first.',
		),
		'results_attrs' => array(
			'I found {n} {wanted}. Newest models first.',
		),
		'budget_between' => array( 'between {min} and {max}' ),
		'budget_max'     => array( 'of up to {max}' ),
		'budget_min'     => array( 'from {min}' ),
		'results_single' => array(
			'I found exactly one option for {label}{en_pais}:',
			'There is one option that fits what you need:',
			'Here is the option I have for {label}:',
		),
		'results_named' => array(
			'Here is {title}!',
			'I found {title}:',
		),
		'results_partial' => array(
			'I could not find an exact match for “{query}”, but these come close:',
			'Nothing matches everything you asked for, so here is the closest I have:',
		),
		'results_refined' => array(
			'Done, I narrowed it down with “{refine}”: {n} left.',
			'Got it, filtered by {refine}. I have {n}.',
		),
		'refine_empty' => array(
			'Adding “{refine}” left nothing, so I searched just for that:',
		),
		'more' => array(
			'Here are {n} more:',
			'A few more you might like:',
			'Check out these other {n}:',
		),
		'no_more' => array(
			'That is everything I have for that search. Want to try something different?',
			'Those were all the results. We can refine it with other words if you like.',
		),
		'none' => array(
			'Hmm, I could not find anything for “{query}”{en_pais}.',
			'No results for “{query}”{en_pais} yet.',
		),
		'none_followup' => array(
			'Want to try other words? You can also browse:',
			'Try describing it differently, or check one of these lines:',
		),
		'none_contact' => array(
			'If you cannot find it, write to us and an advisor will help:',
		),
		'closing' => array(
			'Want to see more, or should we narrow it down?',
			'Give me one more detail (brand, type, color…) and I will narrow it down.',
			'Interested in any of them? I can tell you more.',
			'',
			'',
		),
		'closing_single' => array(
			'Want me to tell you more about this one?',
			'If it is not what you need, give me another detail and I will keep looking.',
			'',
		),
		'clarify' => array(
			'I have {n} for {label}. To narrow it down, are you looking for {facets}?',
			'There are {n} for {label}. Are you more interested in {facets}?',
		),
		'clarify_brand' => array(
			'I have {n} for {label} from several brands. Do you prefer {facets}?',
			'There are {n} for {label}. Any brand in mind: {facets}?',
		),
		'excluded' => array( 'I left out {excluded}.', 'Without {excluded}.' ),
		'learned' => array( 'Sure! This is what you are looking for:', 'Here is what I have for that:' ),
		'fact' => array( 'According to its sheet, {title} has {facts}.', '{title} has {facts}.' ),
		'fact_price' => array( '{title} is priced at {value}.', 'The price of {title} is {value}.' ),
		'fact_brand' => array( '{title} is made by {value}.', 'It is a {value} product.' ),
		'fact_category' => array( '{title} is part of our {value} line.' ),
		'fact_code' => array( 'The code for {title} is {value}.' ),
		'fact_text' => array( 'Here is what the {title} sheet says about that:', 'I found this in the description of {title}:' ),
		'fact_missing' => array( 'I could not find that in the {title} sheet. You can check it or ask us directly:', 'The {title} sheet does not mention that; write to us and we will confirm:' ),
		'fact_price_missing' => array( '{title} has no published price; we can give it to you in a quote:', 'We can confirm the price of {title} with a quote; request it here:' ),
		'fact_followup' => array( 'Anything else you want to know about this product?', 'Want another detail, or shall we compare it with another option?', '' ),
		'describe_brand_category' => array( 'It is a {brand} product in {category}.', 'It belongs to {category} and is made by {brand}.' ),
		'describe_brand' => array( 'It is made by {brand}.' ),
		'describe_category' => array( 'It is part of our {category} line.' ),
		'describe_facts' => array( 'Some details: {facts}.', 'Key facts: {facts}.' ),
		'describe_price' => array( 'Its price is {price}.' ),
		'compare' => array( 'Here is {a} next to {b}:', 'Let us compare {a} and {b}:' ),
		'compare_diff' => array( 'The main difference is {label}: {va} versus {vb}.', 'They differ most in {label}: {va} vs {vb}.' ),
		'compare_cheaper' => array( '{title} is the more affordable of the two.' ),
		'compare_same' => array( 'With the data I have they look very similar; their sheets have all the details.' ),
		'compare_missing' => array( 'Which ones do you want to compare? Tell me both names, for example: “compare the first with the second”.' ),
		'cheapest' => array( 'The most affordable{en_label} is {title}, at {price}.', 'The lowest price{en_label} is {title}: {price}.' ),
		'priciest' => array( 'The highest priced{en_label} is {title}, at {price}.' ),
		'sorted_more' => array( 'Here are the next ones, sorted by price:' ),
		'prices_missing' => array( 'I do not have published prices{en_label}; a quote will give you the exact price:' ),
		'info_quote' => array(
			'contact'   => array( 'This is how you can reach us{en_pais}:', 'Here are our contact details{en_pais}:' ),
			'hours'     => array( 'These are our opening hours{en_pais}:', 'Here are our business hours{en_pais}:' ),
			'location'  => array( 'This is where we are{en_pais}:' ),
			'about'     => array( 'Let me tell you a bit about us:', 'This is {site}:' ),
			'quote'     => array( 'To request a quote:' ),
			'financing' => array( 'About financing:' ),
			'jobs'      => array( 'If you want to work with us:' ),
			'human'     => array( 'An advisor can help you directly:' ),
			'page'      => array( 'Here is what our site says about that:', 'I found this:' ),
		),
		'info_quote_more' => array( 'More details here:', 'You can see all the information here:' ),
		'price_note' => array(
			'Prices may vary; for an exact quote you can contact us here:',
		),
		'detail' => array(
			'Here is what I have about {title}:',
			'Sure, about {title}:',
		),
		'detail_buy' => array(
			'If you like it, its page has all the details and you can buy it there.',
		),
		'detail_cta' => array(
			'If you are interested, you can request a quote or more information here:',
		),
		'reference_missing' => array(
			'Which one do you mean? Tell me its name or its number in the list.',
		),
		'info' => array(
			'contact'   => array( 'Sure, here is how to reach us{en_pais}:', 'You can get in touch with us here:' ),
			'location'  => array( 'Here is where you can find us{en_pais}:', 'These are our locations{en_pais}:' ),
			'location_specific' => array( 'Here is the location you are looking for:', 'Yes, we have this location:' ),
			'location_list'     => array( 'These are all our locations{en_pais}:' ),
			'location_none'     => array( 'I could not find a location in “{query}”, but these are all of ours:' ),
			'location_page'     => array( 'You can also see the map and all the details here:' ),
			'hours'     => array( 'You can find our business hours here:' ),
			'about'     => array( 'Here is a bit about us:', 'Learn more about {site} here:' ),
			'quote'     => array( 'Of course. You can request your quote here:' ),
			'financing' => array( 'Here is the information about financing:' ),
			'jobs'      => array( 'If you would like to work with us, here are the opportunities:' ),
			'human'     => array( 'Of course, an advisor can help you directly. Here is how to reach us:' ),
			'page'      => array( 'I found this information that may help:', 'This might help:' ),
		),
		'info_missing' => array(
			'I could not find a specific page for that, but you can contact us here and we will help:',
		),
		'language_hint' => array( 'I see you are writing in {other_language}; we also have the site in that language:' ),
		'also_info'     => array( 'This may also help:' ),
		'closing_similar' => array( 'Is any of these what you need? If not, give me another detail and I will keep looking.', 'If none of these fit, tell me a bit more and I will search again.' ),
		'similar'       => array( 'You might also like these similar ones:', 'Here are some similar options too:' ),
		'corrected'     => array( 'I think you meant “{fixed}”.', 'I searched for “{fixed}”, which I think is what you meant.' ),
	),

	'ui' => array(
		'page_title'       => 'Assistant',
		'page_slug'        => 'assistant',
		'placeholder'      => 'Type what you are looking for…',
		'send'             => 'Send',
		'new'              => 'New conversation',
		'typing'           => 'Typing…',
		'error'            => 'I could not answer right now. Please try again in a few seconds.',
		'rate_limited'     => 'You are going a bit fast. Wait a moment and try again.',
		'code'             => 'Code',
		'view'             => 'View',
		'noscript'         => 'Enable JavaScript to use the assistant.',
		'choice_title'     => 'How would you like to search?',
		'choice_assistant' => 'Ask the assistant',
		'choice_search'    => 'See search results',
		'you'              => 'You',
		'view_place'       => 'View location',
	),

	'language_names' => array( 'es' => 'Spanish', 'en' => 'English', 'pt' => 'Portuguese', 'fr' => 'French' ),
);
