<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { CheckCircleIcon, ClockIcon, DocumentArrowDownIcon, PlusIcon } from '@heroicons/vue/24/outline'
import { api, ApiError } from '@/api'
import { ask } from '@/confirm'
import { useAuth } from '@/stores/auth'
import { downloadPdf } from '@/exporters'
import { formatDate } from '@/money'
import Modal from '@/components/Modal.vue'

interface Question {
  id: number
  topic: string
  question: string
  status: 'open' | 'decided'
  setting_key: string | null
  setting_label: string | null
  current_value: string | null
  current_words: string | null
  options: Record<string, string> | null
  decision: string | null
  decided_value: string | null
  decided_words: string | null
  meeting: string | null
  decided_on: string | null
  decided_by: string | null
}
interface Rule {
  key: string
  label: string
}

/**
 * Questions waiting for the committee: what the system does meanwhile (the rule on Setup), and,
 * once decided, the decision, the meeting and the value applied. The super admin keeps the list;
 * everyone in the office can see it, and print it as the agenda.
 */
const auth = useAuth()
const questions = ref<Question[]>([])
const rules = ref<Rule[]>([])
const loading = ref(true)
const error = ref('')
const open = computed(() => questions.value.filter((q) => q.status === 'open'))
const decided = computed(() => questions.value.filter((q) => q.status === 'decided'))

async function load() {
  try {
    const [q, s] = await Promise.all([api.get<{ questions: Question[] }>('/committee'), api.get<{ settings: Rule[] }>('/settings')])
    questions.value = q.questions
    rules.value = s.settings
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t load the questions.'
  } finally {
    loading.value = false
  }
}
onMounted(load)

// Adding or changing a question
const editing = ref<{ id: number | null; topic: string; question: string; setting_key: string } | null>(null)
const formError = ref('')
const busy = ref(false)
function startEdit(q?: Question) {
  formError.value = ''
  editing.value = q ? { id: q.id, topic: q.topic, question: q.question, setting_key: q.setting_key ?? '' } : { id: null, topic: '', question: '', setting_key: '' }
}
async function saveQuestion() {
  if (!editing.value) return
  busy.value = true
  formError.value = ''
  try {
    const body = { topic: editing.value.topic, question: editing.value.question, setting_key: editing.value.setting_key || null }
    if (editing.value.id) await api.patch(`/committee/${editing.value.id}`, body)
    else await api.post('/committee', body)
    editing.value = null
    await load()
  } catch (e) {
    formError.value = e instanceof ApiError ? Object.values(e.errors)[0] ?? e.message : 'Couldn\'t save it.'
  } finally {
    busy.value = false
  }
}
async function remove(q: Question) {
  if (!(await ask(`Remove "${q.topic}" from the list?`))) return
  await api.delete(`/committee/${q.id}`)
  await load()
}

// Recording the decision
const deciding = ref<{ q: Question; decision: string; meeting: string; decided_on: string; apply: boolean; value: string } | null>(null)
function startDecide(q: Question) {
  formError.value = ''
  deciding.value = { q, decision: '', meeting: '', decided_on: new Date().toISOString().slice(0, 10), apply: !!q.setting_key, value: q.current_value ?? '' }
}
async function saveDecision() {
  const d = deciding.value
  if (!d) return
  busy.value = true
  formError.value = ''
  try {
    await api.post(`/committee/${d.q.id}/decide`, { decision: d.decision, meeting: d.meeting, decided_on: d.decided_on, value: d.apply ? d.value : '' })
    deciding.value = null
    await load()
  } catch (e) {
    formError.value = e instanceof ApiError ? Object.values(e.errors)[0] ?? e.message : 'Couldn\'t save the decision.'
  } finally {
    busy.value = false
  }
}
async function reopen(q: Question) {
  if (!(await ask(`Put "${q.topic}" back on the list? The rule stays as it is now.`))) return
  await api.post(`/committee/${q.id}/reopen`)
  await load()
}

/** The open questions as a PDF agenda for the committee's meeting */
function agenda() {
  downloadPdf({
    title: 'Questions for the committee',
    subtitle: `${open.value.length} waiting for a decision · ${new Date().toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' })}`,
    columns: [
      { key: 'n', label: 'No.', type: 'number' },
      { key: 'topic', label: 'Topic', type: 'text' },
      { key: 'question', label: 'Question', type: 'text' },
      { key: 'now', label: 'The system uses now', type: 'text' },
    ],
    rows: open.value.map((q, i) => ({ n: i + 1, topic: q.topic, question: q.question, now: q.setting_label ? `${q.setting_label}: ${q.current_words}` : '-' })),
    totals: null,
  }, { sacco: auth.sacco, user: auth.user?.full_name ?? null })
}
</script>

<template>
  <div class="max-w-4xl">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold">Committee decisions</h1>
        <p class="mt-1 text-sm text-gray-600">Rules waiting for the committee. Until it decides, the system uses what's on Setup, shown with each question.</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <button type="button" class="btn-secondary" :disabled="!open.length" @click="agenda"><DocumentArrowDownIcon class="h-4 w-4" />Agenda (PDF)</button>
        <button v-if="auth.isSuperAdmin" type="button" class="btn-primary" @click="startEdit()"><PlusIcon class="h-4 w-4" />Add a question</button>
      </div>
    </div>

    <div v-if="error" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>
    <p v-if="loading" class="mt-8 text-sm text-gray-500">Loading…</p>

    <template v-else>
      <dl class="mt-6 grid grid-cols-2 gap-3 sm:max-w-md">
        <div class="card p-4"><dt class="flex items-center gap-1.5 text-sm text-gray-600"><ClockIcon class="h-4 w-4" aria-hidden="true" />Waiting</dt><dd class="figure mt-1 text-2xl">{{ open.length }}</dd></div>
        <div class="card p-4"><dt class="flex items-center gap-1.5 text-sm text-gray-600"><CheckCircleIcon class="h-4 w-4" aria-hidden="true" />Decided</dt><dd class="figure mt-1 text-2xl">{{ decided.length }}</dd></div>
      </dl>

      <h2 class="mt-8 font-semibold">Waiting for the committee</h2>
      <p v-if="!open.length" class="card mt-3 p-6 text-center text-sm text-gray-500">Nothing is waiting. Every question has been decided.</p>
      <ul v-else class="mt-3 space-y-3">
        <li v-for="q in open" :key="q.id" class="card p-5">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0 flex-1">
              <p class="font-semibold">{{ q.topic }}</p>
              <p class="mt-1 text-sm text-gray-700">{{ q.question }}</p>
              <p v-if="q.setting_label" class="mt-3 inline-flex flex-wrap items-center gap-1.5 rounded-lg bg-amber-50 px-3 py-1.5 text-xs text-amber-900">
                Meanwhile the system uses: <span class="font-semibold">{{ q.setting_label }}: {{ q.current_words }}</span>
                <RouterLink :to="{ name: 'settings' }" class="font-semibold underline">Setup</RouterLink>
              </p>
            </div>
            <div v-if="auth.isSuperAdmin" class="flex flex-wrap gap-2">
              <button type="button" class="btn-primary" @click="startDecide(q)">Record the decision</button>
              <button type="button" class="btn-ghost" @click="startEdit(q)">Edit</button>
              <button type="button" class="btn-ghost text-red-700" @click="remove(q)">Remove</button>
            </div>
          </div>
        </li>
      </ul>

      <template v-if="decided.length">
        <h2 class="mt-8 font-semibold">Decided</h2>
        <ul class="card mt-3 divide-y divide-line">
          <li v-for="q in decided" :key="q.id" class="flex flex-wrap items-start justify-between gap-3 p-5">
            <div class="min-w-0 flex-1">
              <p class="flex items-center gap-2 font-semibold"><CheckCircleIcon class="h-5 w-5 text-emerald-600" aria-hidden="true" />{{ q.topic }}</p>
              <p class="mt-1 text-sm text-gray-700">{{ q.decision }}</p>
              <p class="mt-1.5 text-xs text-gray-500">
                {{ q.decided_on ? formatDate(q.decided_on) : '' }}<template v-if="q.meeting"> · {{ q.meeting }}</template><template v-if="q.decided_by"> · recorded by {{ q.decided_by }}</template>
                <template v-if="q.decided_words"> · {{ q.setting_label }} set to <span class="font-semibold text-gray-700">{{ q.decided_words }}</span></template>
              </p>
            </div>
            <button v-if="auth.isSuperAdmin" type="button" class="btn-ghost" @click="reopen(q)">Reopen</button>
          </li>
        </ul>
      </template>
    </template>

    <Modal v-if="editing" :title="editing.id ? 'Change the question' : 'Add a question for the committee'" @close="editing = null">
      <form class="space-y-4" @submit.prevent="saveQuestion">
        <div>
          <label for="q-topic" class="label">Topic</label>
          <input id="q-topic" v-model="editing.topic" class="input" maxlength="120" placeholder="e.g. Membership fee" />
        </div>
        <div>
          <label for="q-question" class="label">Question</label>
          <textarea id="q-question" v-model="editing.question" class="input min-h-[6rem]" />
        </div>
        <div>
          <label for="q-rule" class="label">The rule it decides (optional)</label>
          <select id="q-rule" v-model="editing.setting_key" class="input">
            <option value="">None: a question without a rule</option>
            <option v-for="r in rules" :key="r.key" :value="r.key">{{ r.label }}</option>
          </select>
        </div>
        <p v-if="formError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ formError }}</p>
        <div class="flex justify-end gap-2">
          <button type="button" class="btn-ghost" @click="editing = null">Cancel</button>
          <button type="submit" class="btn-primary" :disabled="busy || !editing.topic || !editing.question">Save</button>
        </div>
      </form>
    </Modal>

    <Modal v-if="deciding" :title="`Decision: ${deciding.q.topic}`" @close="deciding = null">
      <form class="space-y-4" @submit.prevent="saveDecision">
        <p class="text-sm text-gray-600">{{ deciding.q.question }}</p>
        <div>
          <label for="d-decision" class="label">What the committee decided</label>
          <textarea id="d-decision" v-model="deciding.decision" class="input min-h-[5rem]" />
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
          <div>
            <label for="d-meeting" class="label">Meeting (optional)</label>
            <input id="d-meeting" v-model="deciding.meeting" class="input" placeholder="e.g. Committee meeting, March" />
          </div>
          <div>
            <label for="d-date" class="label">Date</label>
            <input id="d-date" v-model="deciding.decided_on" type="date" class="input" />
          </div>
        </div>
        <div v-if="deciding.q.setting_key" class="rounded-xl border border-line p-4">
          <label class="flex items-start gap-2 text-sm">
            <input v-model="deciding.apply" type="checkbox" class="mt-0.5 accent-brand" />
            <span>Change the rule on Setup now: <span class="font-medium">{{ deciding.q.setting_label }}</span> (now {{ deciding.q.current_words }})</span>
          </label>
          <div v-if="deciding.apply" class="mt-3">
            <label for="d-value" class="label">New value</label>
            <select v-if="deciding.q.options" id="d-value" v-model="deciding.value" class="input">
              <option v-for="(words, v) in deciding.q.options" :key="v" :value="v">{{ words }}</option>
            </select>
            <input v-else id="d-value" v-model="deciding.value" class="input tabular-nums" inputmode="decimal" />
          </div>
        </div>
        <p v-if="formError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ formError }}</p>
        <div class="flex justify-end gap-2">
          <button type="button" class="btn-ghost" @click="deciding = null">Cancel</button>
          <button type="submit" class="btn-primary" :disabled="busy || !deciding.decision.trim()">Save the decision</button>
        </div>
      </form>
    </Modal>
  </div>
</template>
