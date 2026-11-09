<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api } from '@/api'

/** Which bank or cash account the money went into or out of; hidden while there is only one */
defineProps<{ label?: string; id?: string }>()
const model = defineModel<string>({ required: true })

interface MoneyAccount { id: number; code: string; name: string; system_key: string | null }
let cached: Promise<MoneyAccount[]> | null = null
const accounts = ref<MoneyAccount[]>([])
onMounted(async () => {
  cached ??= api.get<{ accounts: MoneyAccount[] }>('/money-accounts').then((r) => r.accounts).catch(() => [])
  accounts.value = await cached
  if (!model.value && accounts.value[0]) model.value = String(accounts.value[0].id)
})
</script>

<template>
  <div v-if="accounts.length > 1">
    <label :for="id ?? 'money-account'" class="label">{{ label ?? 'Paid into / out of' }}</label>
    <select :id="id ?? 'money-account'" v-model="model" class="input">
      <option v-for="a in accounts" :key="a.id" :value="String(a.id)">{{ a.code }} · {{ a.name }}</option>
    </select>
  </div>
</template>
