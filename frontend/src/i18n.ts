import { ref } from 'vue'

/**
 * The member side in English, Luganda or Kiswahili. Office screens stay in English. Wording is
 * keyed by the English, so anything not translated yet simply shows in English.
 *
 * The Luganda and Kiswahili here should be checked by a native speaker before go-live.
 */
export type Lang = 'en' | 'lg' | 'sw'
export const LANGS: { code: Lang; name: string }[] = [
  { code: 'en', name: 'English' },
  { code: 'lg', name: 'Luganda' },
  { code: 'sw', name: 'Kiswahili' },
]

const stored = (() => {
  try {
    return localStorage.getItem('sacco-lang') as Lang | null
  } catch {
    return null
  }
})()
export const lang = ref<Lang>(stored && LANGS.some((l) => l.code === stored) ? stored : 'en')
document.documentElement.lang = lang.value

export function setLang(l: Lang) {
  lang.value = l
  document.documentElement.lang = l
  try {
    localStorage.setItem('sacco-lang', l)
  } catch {
    // Not remembered in a private window
  }
}

const LG: Record<string, string> = {
  Dashboard: 'Olupapula olukulu',
  Statement: 'Embalirira yange',
  Receipts: 'Lisiiti',
  Loans: 'Looni',
  'Withdraw savings': 'Ggyayo ku nsimbi ezitereke',
  'Profile & next of kin': "Ebikwata ku nze n'abooluganda",
  'My account': 'Akawunti yange',
  'Good morning': 'Wasuze otya',
  'Good afternoon': 'Osiibye otya',
  'Good evening': 'Osiibye otya',
  'Apply for a loan': 'Saba looni',
  'My statement': 'Embalirira yange',
  'My profile': 'Ebikwata ku nze',
  'My money in the SACCO': 'Ensimbi zange mu SACCO',
  Savings: 'Ensimbi ezitereke',
  'Share capital': 'Emigabo',
  'You can withdraw': 'Osobola okuggyayo',
  'Saved this year': 'Ezitereke omwaka guno',
  'Dividends & profit so far': "Amagoba g'ofunye",
  'Pledged for colleagues': "Ezisuubiziddwa ku looni z'abalala",
  'Borrowing power': "Looni gy'osobola okufuna",
  'You can borrow up to': 'Osobola okwewola okutuuka ku',
  'See my loans': 'Laba looni zange',
  'How my savings have grown': 'Ensimbi zange bwe zikuze',
  'My loan': 'Looni yange',
  'All loans': 'Looni zonna',
  'Recent activity': 'Ebyakakolebwa',
  'Full statement': 'Embalirira yonna',
  'My saver level': "Eddaala lyange ery'okutereka",
  'Welcome back': 'Tukwanirizza nate',
  'Email or employee number': "Email oba ennamba y'omukozi",
  Password: 'Ekigambo ekyekusifu',
  'Sign in': 'Yingira',
  'Signing in…': 'Tuyingira…',
  'Keep me signed in on this phone': 'Sigala nga nnyingidde ku ssimu eno',
  'Sign out': 'Fuluma',
  'Install the app': 'Teeka app ku ssimu',
  Language: 'Olulimi',
  'My member card': 'Kaadi yange ey’obwammemba',
  'Share certificate': "Satifikeeti y'emigabo",
  'held for guarantees': "ezikwatiddwa olw'okweyimirira",
  'free to guarantee': "ez'okweyimirira abalala",
  'Held for guarantees': "Ezikwatiddwa olw'okweyimirira",
  'Free to guarantee': "Ez'okweyimirira abalala",
  'My savings': 'Ensimbi zange ezitereke',
  'Credit standing': "Engeri gy'osasulamu",
  'Good payer': 'Asasula bulungi',
  'Fair payer': 'Asasula mu kigero',
  'Slow payer': 'Asasula mpola',
  'No repayments yet': 'Tannaba kusasula',
  'Add a photo': 'Teekamu ekifaananyi',
  'Change photo': 'Kyusa ekifaananyi',
  'Remove photo': 'Ggyawo ekifaananyi',
  Messages: 'Obubaka',
  'My messages': 'Obubaka bwange',
  'Messages about your account': 'Obubaka ku akawunti yo',
  All: 'Byonna',
  Money: 'Ensimbi',
  Guarantees: 'Okweyimirira',
  Today: 'Leero',
  Yesterday: 'Jjo',
  'Loading…': 'Kirindiriddwa…',
  'Earlier messages': 'Obubaka obusooka',
  'Also sent to your email': 'Bwasindikiddwa ne ku email yo',
  Open: 'Ggulawo',
  'No messages yet. Money in and out of your account, loan news and reminders will show here.': "Tewannaba kufuna bubaka. Ensimbi eziyingira n'eziva ku akawunti yo, amawulire ga looni n'okujjukizibwa bijja kulabikira wano.",
  'Forgot your password?': 'Weerabidde ekigambo ekyekusifu?',
  Email: 'Email',
  'Sending…': 'Kisindikibwa…',
  'Send me a link': 'Nsindikira link',
  'No email on your account?': 'Tolina email ku akawunti yo?',
  'Back to sign in': 'Ddayo okuyingira',
  'Choose a new password': 'Londa ekigambo ekyekusifu ekipya',
  'New password': 'Ekigambo ekyekusifu ekipya',
  'Type it again': 'Kiwandiike nate',
  'Saving…': 'Kiterekebwa…',
  'Save my new password': 'Tereka ekigambo kyange ekipya',
  'Password changed': 'Ekigambo ekyekusifu kikyusiddwa',
  'Send me a new link': 'Nsindikira link empya',
  'Forgot password?': 'Weerabidde ekigambo ekyekusifu?',
  'Enter your email or employee number, and your password.': "Wandiika email oba ennamba y'omukozi, n'ekigambo ekyekusifu.",
}

const SW: Record<string, string> = {
  Dashboard: 'Ukurasa mkuu',
  Statement: 'Taarifa ya akaunti',
  Receipts: 'Stakabadhi',
  Loans: 'Mikopo',
  'Withdraw savings': 'Toa akiba',
  'Profile & next of kin': 'Wasifu na ndugu wa karibu',
  'My account': 'Akaunti yangu',
  'Good morning': 'Habari za asubuhi',
  'Good afternoon': 'Habari za mchana',
  'Good evening': 'Habari za jioni',
  'Apply for a loan': 'Omba mkopo',
  'My statement': 'Taarifa yangu',
  'My profile': 'Wasifu wangu',
  'My money in the SACCO': 'Pesa zangu katika SACCO',
  Savings: 'Akiba',
  'Share capital': 'Hisa',
  'You can withdraw': 'Unaweza kutoa',
  'Saved this year': 'Akiba ya mwaka huu',
  'Dividends & profit so far': 'Gawio na faida hadi sasa',
  'Pledged for colleagues': 'Dhamana kwa wenzako',
  'Borrowing power': 'Uwezo wa kukopa',
  'You can borrow up to': 'Unaweza kukopa hadi',
  'See my loans': 'Angalia mikopo yangu',
  'How my savings have grown': 'Akiba yangu ilivyokua',
  'My loan': 'Mkopo wangu',
  'All loans': 'Mikopo yote',
  'Recent activity': 'Shughuli za karibuni',
  'Full statement': 'Taarifa kamili',
  'My saver level': 'Kiwango changu cha akiba',
  'Welcome back': 'Karibu tena',
  'Email or employee number': 'Barua pepe au namba ya mfanyakazi',
  Password: 'Nenosiri',
  'Sign in': 'Ingia',
  'Signing in…': 'Inaingia…',
  'Keep me signed in on this phone': 'Endelea kuniweka ndani kwenye simu hii',
  'Sign out': 'Toka',
  'Install the app': 'Sakinisha programu',
  Language: 'Lugha',
  'My member card': 'Kadi yangu ya uanachama',
  'Share certificate': 'Cheti cha hisa',
  'held for guarantees': 'zimeshikiliwa kwa dhamana',
  'free to guarantee': 'zinazoweza kudhamini',
  'Held for guarantees': 'Zimeshikiliwa kwa dhamana',
  'Free to guarantee': 'Zinazoweza kudhamini',
  'My savings': 'Akiba yangu',
  'Credit standing': 'Hali ya ulipaji',
  'Good payer': 'Mlipaji mzuri',
  'Fair payer': 'Mlipaji wa wastani',
  'Slow payer': 'Mlipaji wa kuchelewa',
  'No repayments yet': 'Bado hajalipa',
  'Add a photo': 'Weka picha',
  'Change photo': 'Badilisha picha',
  'Remove photo': 'Ondoa picha',
  Messages: 'Ujumbe',
  'My messages': 'Ujumbe wangu',
  'Messages about your account': 'Ujumbe kuhusu akaunti yako',
  All: 'Zote',
  Money: 'Pesa',
  Guarantees: 'Dhamana',
  Today: 'Leo',
  Yesterday: 'Jana',
  'Loading…': 'Inapakia…',
  'Earlier messages': 'Ujumbe wa awali',
  'Also sent to your email': 'Pia umetumwa kwa barua pepe yako',
  Open: 'Fungua',
  'No messages yet. Money in and out of your account, loan news and reminders will show here.': 'Bado hakuna ujumbe. Pesa zinazoingia na kutoka kwenye akaunti yako, habari za mikopo na vikumbusho vitaonekana hapa.',
  'Forgot your password?': 'Umesahau nenosiri?',
  Email: 'Barua pepe',
  'Sending…': 'Inatuma…',
  'Send me a link': 'Nitumie kiungo',
  'No email on your account?': 'Huna barua pepe kwenye akaunti yako?',
  'Back to sign in': 'Rudi kuingia',
  'Choose a new password': 'Chagua nenosiri jipya',
  'New password': 'Nenosiri jipya',
  'Type it again': 'Liandike tena',
  'Saving…': 'Inahifadhi…',
  'Save my new password': 'Hifadhi nenosiri langu jipya',
  'Password changed': 'Nenosiri limebadilishwa',
  'Send me a new link': 'Nitumie kiungo kipya',
  'Forgot password?': 'Umesahau nenosiri?',
  'Enter your email or employee number, and your password.': 'Andika barua pepe au namba ya mfanyakazi, na nenosiri lako.',
}

/** The member-side wording in the chosen language */
export function t(english: string): string {
  if (lang.value === 'lg') return LG[english] ?? english
  if (lang.value === 'sw') return SW[english] ?? english
  return english
}
