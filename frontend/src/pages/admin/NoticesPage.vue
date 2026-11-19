<script setup lang="ts">
import PageHeader from '@/components/PageHeader.vue'
import EmptyState from '@/components/EmptyState.vue'
import { ask } from '@/confirm'
import { onMounted, reactive, ref } from 'vue'
import { api, ApiError } from '@/api'
import { formatDate } from '@/money'
import StatusBadge from '@/components/StatusBadge.vue'

interface Notice {
  id: number
  title: string
  body: string
  starts_on: string
  ends_on: string | null
  created_by_name: string | null
}

/** Notices from the office, shown on every member's dashboard while they run */
const today = new Date().toLocaleDateString('en-CA')
const notices = ref<Notice[]>([])
const form = reactive({ title: '', body: '', starts_on: today, ends_on: '' })
const error = ref('')
const busy = ref(false)

async function load() {
  notices.value = (await api.get<{ notices: Notice[] }>('/notices')).notices
}
onMounted(load)

function state(n: Notice) {
  if (n.starts_on > today) return { label: 'Scheduled', style: 'bg-navy-50 text-navy-800' }
  if (n.ends_on && n.ends_on < today) return { label: 'Ended', style: 'bg-gray-100 text-gray-600' }
  return { label: 'Showing now', style: 'bg-emerald-50 text-emerald-800' }
}

async function post() {
  error.value = ''
  busy.value = true
  try {
    await api.post('/notices', { ...form, ends_on: form.ends_on || null })
    Object.assign(form, { title: '', body: '', starts_on: today, ends_on: '' })
    await load()
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t post the notice.'
  } finally {
    busy.value = false
  }
}

async function end(n: Notice) {
  if (!await ask(`Take down "${n.title}"?`)) return
  await api.delete(`/notices/${n.id}`)
  await load()
}
</script>

<template>
  <div>
    <PageHeader eyebrow="Members" title="Notices">
      Tell every member something: AGM dates, rule changes, when payroll goes in. Notices show on members' dashboards.
    </PageHeader>

    <!-- Phones and laptops: the form, then the notices. Wide screens: the form on the left (it stays
         in view while the list scrolls), the notices on the right, in two columns on very wide ones. -->
    <div class="mt-5 grid items-start gap-5 xl:grid-cols-[minmax(0,26rem)_minmax(0,1fr)] 2xl:grid-cols-[minmax(0,30rem)_minmax(0,1fr)]">
    <form v-can="'notices'" class="card space-y-4 p-4 sm:p-5 xl:sticky xl:top-20" novalidate @submit.prevent="post">
      <h2 class="font-semibold">New notice</h2>
      <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>
      <div><label for="ntitle" class="label">Title</label><input id="ntitle" v-model="form.title" class="input" maxlength="120" placeholder="e.g. Annual General Meeting on 20 December" /></div>
      <div><label for="nbody" class="label">Message</label><textarea id="nbody" v-model="form.body" class="input min-h-[7rem] xl:min-h-[10rem]" maxlength="5000" /></div>
      <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-3 xl:grid-cols-2">
        <div><label for="nstart" class="label">Show from</label><input id="nstart" v-model="form.starts_on" type="date" class="input" /></div>
        <div><label for="nend" class="label">Until <span class="font-normal text-gray-400">(optional)</span></label><input id="nend" v-model="form.ends_on" type="date" :min="form.starts_on" class="input" /></div>
        <div class="col-span-2 flex items-end lg:col-span-1 xl:col-span-2"><button type="submit" class="btn-primary w-full" :disabled="busy || !form.title.trim() || !form.body.trim()">Post notice</button></div>
      </div>
    </form>

    <section class="min-w-0" aria-labelledby="posted">
    <h2 id="posted" class="mb-3 flex items-center gap-2 font-semibold">
      Posted notices<span v-if="notices.length" class="rounded-full bg-[#f1edff] px-2 py-0.5 text-xs font-bold text-[#6246ea] dark:bg-white/10 dark:text-navy-200">{{ notices.length }}</span>
    </h2>
    <ul class="grid gap-3 2xl:grid-cols-2">
      <li v-for="n in notices" :key="n.id" class="card flex flex-col p-4 sm:p-5">
        <div class="flex flex-wrap items-start justify-between gap-2">
          <h3 class="font-semibold">{{ n.title }}</h3>
          <StatusBadge :label="state(n).label" :style-class="state(n).style" />
        </div>
        <p class="mt-2 flex-1 whitespace-pre-line break-words text-sm text-gray-700">{{ n.body }}</p>
        <div class="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-line pt-3 text-xs text-gray-500">
          <span>{{ formatDate(n.starts_on) }}<template v-if="n.ends_on"> – {{ formatDate(n.ends_on) }}</template> · by {{ n.created_by_name }}</span>
          <button v-can="'notices'" v-if="state(n).label !== 'Ended'" type="button" class="btn-ghost px-2 py-1 text-xs" @click="end(n)">Take down</button>
        </div>
      </li>
      <li v-if="!notices.length" class="card 2xl:col-span-2"><EmptyState title="No notices yet" text="Notices appear on every member's dashboard, for example the AGM date or a change in the rules." /></li>
    </ul>
    </section>
    </div>
  </div>
</template>
