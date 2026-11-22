/**
 * Countries for the signup's phone fields: ISO code and dial code (the list
 * Chatwoot's phone input uses), and which country each browser time zone is in
 * (countries-and-timezones, legacy names included), so a field starts on the
 * visitor's country. Names come from Intl.DisplayNames in the page's language;
 * flags are flag-icons SVGs (MIT) under img/flags.
 */
const COUNTRIES = 'AF:93,AX:358,AL:355,DZ:213,AS:1684,AD:376,AO:244,AI:1264,AQ:672,AG:1268,AR:54,AM:374,AW:297,AU:61,AT:43,AZ:994,BS:1242,BH:973,BD:880,BB:1246,BY:375,BE:32,BZ:501,BJ:229,BM:1441,BT:975,BO:591,BA:387,BW:267,BR:55,IO:246,BN:673,BG:359,BF:226,BI:257,KH:855,CM:237,CA:1,CV:238,KY:345,CF:236,TD:235,CL:56,CN:86,CX:61,CC:61,CO:57,KM:269,CG:242,CD:243,CK:682,CR:506,CI:225,HR:385,CU:53,CY:357,CZ:420,DK:45,DJ:253,DM:1767,DO:1849,EC:593,EG:20,SV:503,GQ:240,ER:291,EE:372,ET:251,FK:500,FO:298,FJ:679,FI:358,FR:33,GF:594,PF:689,GA:241,GM:220,GE:995,DE:49,GH:233,GI:350,GR:30,GL:299,GD:1473,GP:590,GU:1671,GT:502,GG:44,GN:224,GW:245,GY:592,HT:509,VA:379,HN:504,HK:852,HU:36,IS:354,IN:91,ID:62,IR:98,IQ:964,IE:353,IM:44,IL:972,IT:39,JM:1876,JP:81,JE:44,JO:962,KZ:77,KE:254,KI:686,KP:850,KR:82,KW:965,KG:996,LA:856,LV:371,LB:961,LS:266,LR:231,LY:218,LI:423,LT:370,LU:352,MO:853,MK:389,MG:261,MW:265,MY:60,MV:960,ML:223,MT:356,MH:692,MQ:596,MR:222,MU:230,YT:262,MX:52,FM:691,MD:373,MC:377,MN:976,ME:382,MS:1664,MA:212,MZ:258,MM:95,NA:264,NR:674,NP:977,NL:31,NC:687,NZ:64,NI:505,NE:227,NG:234,NU:683,NF:672,MP:1670,NO:47,OM:968,PK:92,PW:680,PS:970,PA:507,PG:675,PY:595,PE:51,PH:63,PN:872,PL:48,PT:351,PR:1939,QA:974,RO:40,RU:7,RW:250,RE:262,BL:590,SH:290,KN:1869,LC:1758,MF:590,PM:508,VC:1784,WS:685,SM:378,ST:239,SA:966,SN:221,RS:381,SC:248,SL:232,SG:65,SK:421,SI:386,SB:677,SO:252,ZA:27,SS:211,GS:500,ES:34,LK:94,SD:249,SR:597,SJ:47,SZ:268,SE:46,CH:41,SY:963,TW:886,TJ:992,TZ:255,TH:66,TL:670,TG:228,TK:690,TO:676,TT:1868,TN:216,TR:90,TM:993,TC:1649,TV:688,UG:256,UA:380,AE:971,GB:44,US:1,UY:598,UZ:998,VU:678,VE:58,VN:84,VG:1284,VI:1340,WF:681,YE:967,ZM:260,ZW:263';

const ZONES = {
    AD: 'Europe/Andorra',
    AE: 'Asia/Dubai',
    AF: 'Asia/Kabul',
    AG: 'America/Antigua',
    AI: 'America/Anguilla',
    AL: 'Europe/Tirane',
    AM: 'Asia/Yerevan',
    AO: 'Africa/Luanda',
    AQ: 'Antarctica/Casey Antarctica/Davis Antarctica/DumontDUrville Antarctica/Mawson Antarctica/McMurdo Antarctica/Palmer Antarctica/Rothera Antarctica/South_Pole Antarctica/Syowa Antarctica/Troll Antarctica/Vostok',
    AR: 'America/Argentina/Buenos_Aires America/Argentina/Catamarca America/Argentina/ComodRivadavia America/Argentina/Cordoba America/Argentina/Jujuy America/Argentina/La_Rioja America/Argentina/Mendoza America/Argentina/Rio_Gallegos America/Argentina/Salta America/Argentina/San_Juan America/Argentina/San_Luis America/Argentina/Tucuman America/Argentina/Ushuaia America/Buenos_Aires America/Catamarca America/Cordoba America/Jujuy America/Mendoza America/Rosario',
    AS: 'Pacific/Pago_Pago Pacific/Samoa US/Samoa',
    AT: 'Europe/Vienna',
    AU: 'Antarctica/Macquarie Australia/ACT Australia/Adelaide Australia/Brisbane Australia/Broken_Hill Australia/Canberra Australia/Currie Australia/Darwin Australia/Eucla Australia/Hobart Australia/LHI Australia/Lindeman Australia/Lord_Howe Australia/Melbourne Australia/NSW Australia/North Australia/Perth Australia/Queensland Australia/South Australia/Sydney Australia/Tasmania Australia/Victoria Australia/West Australia/Yancowinna',
    AW: 'America/Aruba',
    AX: 'Europe/Mariehamn',
    AZ: 'Asia/Baku',
    BA: 'Europe/Sarajevo',
    BB: 'America/Barbados',
    BD: 'Asia/Dacca Asia/Dhaka',
    BE: 'Europe/Brussels',
    BF: 'Africa/Ouagadougou',
    BG: 'Europe/Sofia',
    BH: 'Asia/Bahrain',
    BI: 'Africa/Bujumbura',
    BJ: 'Africa/Porto-Novo',
    BL: 'America/St_Barthelemy',
    BM: 'Atlantic/Bermuda',
    BN: 'Asia/Brunei',
    BO: 'America/La_Paz',
    BR: 'America/Araguaina America/Bahia America/Belem America/Boa_Vista America/Campo_Grande America/Cuiaba America/Eirunepe America/Fortaleza America/Maceio America/Manaus America/Noronha America/Porto_Acre America/Porto_Velho America/Recife America/Rio_Branco America/Santarem America/Sao_Paulo Brazil/Acre Brazil/DeNoronha Brazil/East Brazil/West',
    BS: 'America/Nassau',
    BT: 'Asia/Thimbu Asia/Thimphu',
    BW: 'Africa/Gaborone',
    BY: 'Europe/Minsk',
    BZ: 'America/Belize',
    CA: 'America/Atikokan America/Blanc-Sablon America/Cambridge_Bay America/Coral_Harbour America/Creston America/Dawson America/Dawson_Creek America/Edmonton America/Fort_Nelson America/Glace_Bay America/Goose_Bay America/Halifax America/Inuvik America/Iqaluit America/Moncton America/Montreal America/Nipigon America/Pangnirtung America/Rainy_River America/Rankin_Inlet America/Regina America/Resolute America/St_Johns America/Swift_Current America/Thunder_Bay America/Toronto America/Vancouver America/Whitehorse America/Winnipeg America/Yellowknife Canada/Atlantic Canada/Central Canada/Eastern Canada/Mountain Canada/Newfoundland Canada/Pacific Canada/Saskatchewan Canada/Yukon',
    CC: 'Indian/Cocos',
    CD: 'Africa/Kinshasa Africa/Lubumbashi',
    CF: 'Africa/Bangui',
    CG: 'Africa/Brazzaville',
    CH: 'Europe/Zurich',
    CI: 'Africa/Abidjan',
    CK: 'Pacific/Rarotonga',
    CL: 'America/Punta_Arenas America/Santiago Chile/Continental Chile/EasterIsland Pacific/Easter',
    CM: 'Africa/Douala',
    CN: 'Asia/Chongqing Asia/Chungking Asia/Harbin Asia/Kashgar Asia/Shanghai Asia/Urumqi PRC',
    CO: 'America/Bogota',
    CR: 'America/Costa_Rica',
    CU: 'America/Havana Cuba',
    CV: 'Atlantic/Cape_Verde',
    CX: 'Indian/Christmas',
    CY: 'Asia/Famagusta Asia/Nicosia Europe/Nicosia',
    CZ: 'Europe/Prague',
    DE: 'Europe/Berlin Europe/Busingen',
    DJ: 'Africa/Djibouti',
    DK: 'Europe/Copenhagen',
    DM: 'America/Dominica',
    DO: 'America/Santo_Domingo',
    DZ: 'Africa/Algiers',
    EC: 'America/Guayaquil Pacific/Galapagos',
    EE: 'Europe/Tallinn',
    EG: 'Africa/Cairo Egypt',
    ER: 'Africa/Asmara Africa/Asmera',
    ES: 'Africa/Ceuta Atlantic/Canary Europe/Madrid',
    ET: 'Africa/Addis_Ababa',
    FI: 'Europe/Helsinki',
    FJ: 'Pacific/Fiji',
    FK: 'Atlantic/Stanley',
    FM: 'Pacific/Chuuk Pacific/Kosrae Pacific/Pohnpei Pacific/Ponape Pacific/Truk Pacific/Yap',
    FO: 'Atlantic/Faeroe Atlantic/Faroe',
    FR: 'Europe/Paris',
    GA: 'Africa/Libreville',
    GB: 'Europe/Belfast Europe/London GB GB-Eire',
    GD: 'America/Grenada',
    GE: 'Asia/Tbilisi',
    GF: 'America/Cayenne',
    GG: 'Europe/Guernsey',
    GH: 'Africa/Accra',
    GI: 'Europe/Gibraltar',
    GL: 'America/Danmarkshavn America/Godthab America/Nuuk America/Scoresbysund America/Thule',
    GM: 'Africa/Banjul',
    GN: 'Africa/Conakry',
    GP: 'America/Guadeloupe',
    GQ: 'Africa/Malabo',
    GR: 'Europe/Athens',
    GS: 'Atlantic/South_Georgia',
    GT: 'America/Guatemala',
    GU: 'Pacific/Guam',
    GW: 'Africa/Bissau',
    GY: 'America/Guyana',
    HK: 'Asia/Hong_Kong Hongkong',
    HN: 'America/Tegucigalpa',
    HR: 'Europe/Zagreb',
    HT: 'America/Port-au-Prince',
    HU: 'Europe/Budapest',
    ID: 'Asia/Jakarta Asia/Jayapura Asia/Makassar Asia/Pontianak Asia/Ujung_Pandang',
    IE: 'Eire Europe/Dublin',
    IL: 'Asia/Jerusalem Asia/Tel_Aviv Israel',
    IM: 'Europe/Isle_of_Man',
    IN: 'Asia/Calcutta Asia/Kolkata',
    IO: 'Indian/Chagos',
    IQ: 'Asia/Baghdad',
    IR: 'Asia/Tehran Iran',
    IS: 'Atlantic/Reykjavik Iceland',
    IT: 'Europe/Rome',
    JE: 'Europe/Jersey',
    JM: 'America/Jamaica Jamaica',
    JO: 'Asia/Amman',
    JP: 'Asia/Tokyo Japan',
    KE: 'Africa/Nairobi',
    KG: 'Asia/Bishkek',
    KH: 'Asia/Phnom_Penh',
    KI: 'Pacific/Enderbury Pacific/Kanton Pacific/Kiritimati Pacific/Tarawa',
    KM: 'Indian/Comoro',
    KN: 'America/St_Kitts',
    KP: 'Asia/Pyongyang',
    KR: 'Asia/Seoul ROK',
    KW: 'Asia/Kuwait',
    KY: 'America/Cayman',
    KZ: 'Asia/Almaty Asia/Aqtau Asia/Aqtobe Asia/Atyrau Asia/Oral Asia/Qostanay Asia/Qyzylorda',
    LA: 'Asia/Vientiane',
    LB: 'Asia/Beirut',
    LC: 'America/St_Lucia',
    LI: 'Europe/Vaduz',
    LK: 'Asia/Colombo',
    LR: 'Africa/Monrovia',
    LS: 'Africa/Maseru',
    LT: 'Europe/Vilnius',
    LU: 'Europe/Luxembourg',
    LV: 'Europe/Riga',
    LY: 'Africa/Tripoli Libya',
    MA: 'Africa/Casablanca',
    MC: 'Europe/Monaco',
    MD: 'Europe/Chisinau Europe/Tiraspol',
    ME: 'Europe/Podgorica',
    MF: 'America/Marigot',
    MG: 'Indian/Antananarivo',
    MH: 'Kwajalein Pacific/Kwajalein Pacific/Majuro',
    MK: 'Europe/Skopje',
    ML: 'Africa/Bamako Africa/Timbuktu',
    MM: 'Asia/Rangoon Asia/Yangon',
    MN: 'Asia/Choibalsan Asia/Hovd Asia/Ulaanbaatar Asia/Ulan_Bator',
    MO: 'Asia/Macao Asia/Macau',
    MP: 'Pacific/Saipan',
    MQ: 'America/Martinique',
    MR: 'Africa/Nouakchott',
    MS: 'America/Montserrat',
    MT: 'Europe/Malta',
    MU: 'Indian/Mauritius',
    MV: 'Indian/Maldives',
    MW: 'Africa/Blantyre',
    MX: 'America/Bahia_Banderas America/Cancun America/Chihuahua America/Ciudad_Juarez America/Ensenada America/Hermosillo America/Matamoros America/Mazatlan America/Merida America/Mexico_City America/Monterrey America/Ojinaga America/Santa_Isabel America/Tijuana Mexico/BajaNorte Mexico/BajaSur Mexico/General',
    MY: 'Asia/Kuala_Lumpur Asia/Kuching',
    MZ: 'Africa/Maputo',
    NA: 'Africa/Windhoek',
    NC: 'Pacific/Noumea',
    NE: 'Africa/Niamey',
    NF: 'Pacific/Norfolk',
    NG: 'Africa/Lagos',
    NI: 'America/Managua',
    NL: 'Europe/Amsterdam',
    NO: 'Europe/Oslo',
    NP: 'Asia/Kathmandu Asia/Katmandu',
    NR: 'Pacific/Nauru',
    NU: 'Pacific/Niue',
    NZ: 'NZ NZ-CHAT Pacific/Auckland Pacific/Chatham',
    OM: 'Asia/Muscat',
    PA: 'America/Panama',
    PE: 'America/Lima',
    PF: 'Pacific/Gambier Pacific/Marquesas Pacific/Tahiti',
    PG: 'Pacific/Bougainville Pacific/Port_Moresby',
    PH: 'Asia/Manila',
    PK: 'Asia/Karachi',
    PL: 'Europe/Warsaw Poland',
    PM: 'America/Miquelon',
    PN: 'Pacific/Pitcairn',
    PR: 'America/Puerto_Rico',
    PS: 'Asia/Gaza Asia/Hebron',
    PT: 'Atlantic/Azores Atlantic/Madeira Europe/Lisbon Portugal',
    PW: 'Pacific/Palau',
    PY: 'America/Asuncion',
    QA: 'Asia/Qatar',
    RE: 'Indian/Reunion',
    RO: 'Europe/Bucharest',
    RS: 'Europe/Belgrade',
    RU: 'Asia/Anadyr Asia/Barnaul Asia/Chita Asia/Irkutsk Asia/Kamchatka Asia/Khandyga Asia/Krasnoyarsk Asia/Magadan Asia/Novokuznetsk Asia/Novosibirsk Asia/Omsk Asia/Sakhalin Asia/Srednekolymsk Asia/Tomsk Asia/Ust-Nera Asia/Vladivostok Asia/Yakutsk Asia/Yekaterinburg Europe/Astrakhan Europe/Kaliningrad Europe/Kirov Europe/Moscow Europe/Samara Europe/Saratov Europe/Simferopol Europe/Ulyanovsk Europe/Volgograd W-SU',
    RW: 'Africa/Kigali',
    SA: 'Asia/Riyadh',
    SB: 'Pacific/Guadalcanal',
    SC: 'Indian/Mahe',
    SD: 'Africa/Khartoum',
    SE: 'Europe/Stockholm',
    SG: 'Asia/Singapore Singapore',
    SH: 'Atlantic/St_Helena',
    SI: 'Europe/Ljubljana',
    SJ: 'Arctic/Longyearbyen Atlantic/Jan_Mayen',
    SK: 'Europe/Bratislava',
    SL: 'Africa/Freetown',
    SM: 'Europe/San_Marino',
    SN: 'Africa/Dakar',
    SO: 'Africa/Mogadishu',
    SR: 'America/Paramaribo',
    SS: 'Africa/Juba',
    ST: 'Africa/Sao_Tome',
    SV: 'America/El_Salvador',
    SY: 'Asia/Damascus',
    SZ: 'Africa/Mbabane',
    TC: 'America/Grand_Turk',
    TD: 'Africa/Ndjamena',
    TG: 'Africa/Lome',
    TH: 'Asia/Bangkok',
    TJ: 'Asia/Dushanbe',
    TK: 'Pacific/Fakaofo',
    TL: 'Asia/Dili',
    TM: 'Asia/Ashgabat Asia/Ashkhabad',
    TN: 'Africa/Tunis',
    TO: 'Pacific/Tongatapu',
    TR: 'Asia/Istanbul Europe/Istanbul Turkey',
    TT: 'America/Port_of_Spain',
    TV: 'Pacific/Funafuti',
    TW: 'Asia/Taipei ROC',
    TZ: 'Africa/Dar_es_Salaam',
    UA: 'Europe/Kiev Europe/Kyiv Europe/Uzhgorod Europe/Zaporozhye',
    UG: 'Africa/Kampala',
    US: 'America/Adak America/Anchorage America/Atka America/Boise America/Chicago America/Denver America/Detroit America/Fort_Wayne America/Indiana/Indianapolis America/Indiana/Knox America/Indiana/Marengo America/Indiana/Petersburg America/Indiana/Tell_City America/Indiana/Vevay America/Indiana/Vincennes America/Indiana/Winamac America/Indianapolis America/Juneau America/Kentucky/Louisville America/Kentucky/Monticello America/Knox_IN America/Los_Angeles America/Louisville America/Menominee America/Metlakatla America/New_York America/Nome America/North_Dakota/Beulah America/North_Dakota/Center America/North_Dakota/New_Salem America/Phoenix America/Shiprock America/Sitka America/Yakutat Navajo Pacific/Honolulu Pacific/Johnston US/Alaska US/Aleutian US/Arizona US/Central US/East-Indiana US/Eastern US/Hawaii US/Indiana-Starke US/Michigan US/Mountain US/Pacific',
    UY: 'America/Montevideo',
    UZ: 'Asia/Samarkand Asia/Tashkent',
    VA: 'Europe/Vatican',
    VC: 'America/St_Vincent',
    VE: 'America/Caracas',
    VG: 'America/Tortola',
    VI: 'America/St_Thomas America/Virgin',
    VN: 'Asia/Ho_Chi_Minh Asia/Saigon',
    VU: 'Pacific/Efate',
    WF: 'Pacific/Wallis',
    WS: 'Pacific/Apia',
    YE: 'Asia/Aden',
    YT: 'Indian/Mayotte',
    ZA: 'Africa/Johannesburg',
    ZM: 'Africa/Lusaka',
    ZW: 'Africa/Harare',
};

const FLAGS_PATH = 'client/custom/modules/feature-self-service/img/flags/';

/** Who a shared dial code means when nothing else tells. */
const MAIN = {'1': 'US', '7': 'RU', '44': 'GB', '47': 'NO', '61': 'AU', '590': 'GP', '262': 'RE', '599': 'CW'};

/** @type {Array<{iso: string, dial: string}>} */
export const countries = COUNTRIES.split(',').map(item => {
    const [iso, dial] = item.split(':');

    return {iso, dial};
});

const zoneCountry = {};

Object.entries(ZONES).forEach(([iso, zones]) => zones.split(' ').forEach(zone => {
    zoneCountry[zone] = iso;
}));

export const flagUrl = iso => FLAGS_PATH + iso.toLowerCase() + '.svg';

export const byIso = iso => countries.find(country => country.iso === iso) || null;

/** The browser's time zone, else its language's region, else Brazil. */
export function defaultCountry() {
    let zone = '';

    try {
        zone = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
    } catch (e) {
        zone = '';
    }

    const fromZone = zoneCountry[zone];
    const fromLanguage = ((navigator.language || '').split('-')[1] || '').toUpperCase();

    return (fromZone && byIso(fromZone)) || byIso(fromLanguage) || byIso('BR');
}

/** The country's name in the page's language ("Brasil", "Brazil"), else its ISO code. */
export function countryName(iso, language) {
    try {
        return new Intl.DisplayNames([language], {type: 'region'}).of(iso) || iso;
    } catch (e) {
        return iso;
    }
}

/**
 * A stored number ("+5511912345678") split into its country and the rest, by
 * the longest dial code it starts with; anything else stays as typed.
 */
export function splitPhone(value, fallback) {
    const text = String(value || '').trim();

    if (!text.startsWith('+')) {
        return {country: fallback, national: text};
    }

    const digits = text.replace(/\D/g, '');
    const match = countries
        .filter(country => digits.startsWith(country.dial))
        .sort((a, b) => b.dial.length - a.dial.length || (MAIN[b.dial] === b.iso) - (MAIN[a.dial] === a.iso))[0];

    if (!match) {
        return {country: fallback, national: text};
    }

    // Several countries share +1 or +7: keep the one already chosen when it fits.
    const country = fallback && fallback.dial === match.dial ? fallback : match;

    return {country, national: digits.slice(match.dial.length)};
}

/** What the CRM gets: "+<dial><number>", or the number as typed when it already starts with "+". */
export function joinPhone(country, national) {
    const text = String(national || '').trim();

    if (text.startsWith('+')) {
        return text;
    }

    const digits = text.replace(/\D/g, '').replace(/^0+/, '');

    return digits ? '+' + country.dial + digits : '';
}
