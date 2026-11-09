import { ref } from 'vue'

/** The question showing now; ConfirmHost (in App.vue) draws it */
export const question = ref<{ text: string; resolve: (yes: boolean) => void } | null>(null)

/** Asks before something that can't be casually undone: `if (!await ask('Post the payroll?')) return` */
export function ask(text: string): Promise<boolean> {
  question.value?.resolve(false)
  return new Promise((resolve) => {
    question.value = { text, resolve }
  })
}

export function answer(yes: boolean) {
  question.value?.resolve(yes)
  question.value = null
}
