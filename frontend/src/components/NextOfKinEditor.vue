<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { PlusIcon, TrashIcon } from '@heroicons/vue/24/outline'
import { api, ApiError } from '@/api'
import type { NextOfKin } from '@/types'

/**
 * Who receives a member's savings and shares if they die. Used by members on their profile and
 * by admins on a member's page. The shares must add up to 100%.
 */
const props = defineProps<{ people: NextOfKin[]; saveUrl: string }>()
const emit = defineEmits<{ saved: [people: NextOfKin[]] }>()

const editing = ref(false)
const rows = ref<NextOfKin[]>([])
const error = ref('')
const busy = ref(false)

watch(() => props.people, (p) => (rows.value = p.map((k) => ({ ...k }))), { immediate: true })

const total = computed(() => rows.value.reduce((s, r) => s + (Number(r.share) || 0), 0))

function add() {
  const left = Math.max(0, 100 - total.value)
  rows.value.push({ full_name: '', relationship: '', phone: '', share: String(left || '') })
}

async function save() {
  error.value = ''
  busy.value = true
  try {
    const res = await api.post<{ next_of_kin: NextOfKin[] }>(props.saveUrl, {
      people: rows.value.map((r) => ({ full_name: r.full_name, relationship: r.relationship, phone: r.phone, share_percent: r.share })),
    })
    emit('saved', res.next_of_kin)
    editing.value = false
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t save.'
  } finally {
    busy.value = false
  }
}

function cancel() {
  rows.value = props.people.map((k) => ({ ...k }))
  editing.value = false
  error.value = ''
}
</script>

<template>
  <section class="card p-5">
    <div class="flex items-start justify-between gap-3">
      <div>
        <h2 class="font-semibold">Next of kin</h2>
        <p class="mt-1 text-sm text-gray-600">Who receives the savings and shares if the member dies.</p>
      </div>
      <button v-can="'members'" v-if="!editing" type="button" class="btn-secondary px-3 py-1.5" @click="editing = true; rows.length || add()">{{ people.length ? 'Change' : 'Add' }}</button>
    </div>

    <template v-if="!editing">
      <ul v-if="people.length" class="mt-4 divide-y divide-line text-sm">
        <li v-for="k in people" :key="k.id" class="flex items-center justify-between gap-3 py-2.5">
          <div>
            <p class="font-medium">{{ k.full_name }}</p>
            <p class="text-gray-600">{{ k.relationship }}<template v-if="k.phone"> · {{ k.phone }}</template></p>
          </div>
          <span class="rounded-full bg-navy-50 px-2.5 py-1 text-xs font-bold tabular-nums text-navy-800">{{ k.share }}%</span>
        </li>
      </ul>
      <p v-else class="mt-4 rounded-xl bg-saffron-50 px-3 py-2 text-sm text-saffron-700">No next of kin recorded yet.</p>
    </template>

    <form v-else class="mt-4 space-y-3" novalidate @submit.prevent="save">
      <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>
      <div v-for="(r, i) in rows" :key="i" class="grid gap-2 rounded-xl border border-line p-3 sm:grid-cols-[1.4fr_1fr_1fr_6rem_auto]">
        <input v-model="r.full_name" class="input" placeholder="Full name" :aria-label="`Next of kin ${i + 1} name`" />
        <input v-model="r.relationship" class="input" placeholder="Relationship" :aria-label="`Next of kin ${i + 1} relationship`" list="relationships" />
        <input v-model="r.phone" class="input" placeholder="Phone" type="tel" :aria-label="`Next of kin ${i + 1} phone`" />
        <div class="relative">
          <input v-model="r.share" class="input pr-7 tabular-nums" inputmode="decimal" :aria-label="`Next of kin ${i + 1} share`" />
          <span class="absolute right-3 top-1/2 -translate-y-1/2 text-sm text-gray-500">%</span>
        </div>
        <button type="button" class="btn-ghost px-2" :aria-label="`Remove next of kin ${i + 1}`" @click="rows.splice(i, 1)"><TrashIcon class="h-4 w-4" /></button>
      </div>
      <datalist id="relationships">
        <option v-for="r in ['Spouse', 'Husband', 'Wife', 'Son', 'Daughter', 'Mother', 'Father', 'Brother', 'Sister', 'Guardian']" :key="r" :value="r" />
      </datalist>
      <div class="flex flex-wrap items-center justify-between gap-2">
        <button type="button" class="btn-ghost px-2 py-1" @click="add"><PlusIcon class="h-4 w-4" />Add a person</button>
        <p class="text-sm" :class="total === 100 || !rows.length ? 'text-gray-600' : 'font-semibold text-amber-700'">Shares add up to {{ total }}%</p>
      </div>
      <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <button type="button" class="btn-secondary" @click="cancel">Cancel</button>
        <button type="submit" class="btn-primary" :disabled="busy || (rows.length > 0 && total !== 100)">Save next of kin</button>
      </div>
    </form>
  </section>
</template>
