<script setup lang="ts">
/** The fields for one loan security (collateral); edits the object it's given */
defineProps<{
  security: { kind: string; description: string; value: string; reference: string; kept_at: string }
  kinds: string[]
}>()
</script>

<template>
  <div class="grid gap-3 sm:grid-cols-2">
    <div>
      <label for="sec-kind" class="label">Kind of security</label>
      <select id="sec-kind" v-model="security.kind" class="input">
        <option value="">Choose…</option>
        <option v-for="k in kinds" :key="k" :value="k">{{ k }}</option>
      </select>
    </div>
    <div>
      <label for="sec-value" class="label">Estimated value <span class="font-normal text-gray-500">(optional)</span></label>
      <input id="sec-value" v-model="security.value" class="input tabular-nums" inputmode="numeric" placeholder="e.g. 15,000,000" />
    </div>
    <div class="sm:col-span-2">
      <label for="sec-desc" class="label">Description</label>
      <input id="sec-desc" v-model="security.description" class="input" maxlength="255" placeholder="e.g. Plot 12, Block 4, Mukono, in the borrower's name" />
    </div>
    <div>
      <label for="sec-ref" class="label">Document number <span class="font-normal text-gray-500">(optional)</span></label>
      <input id="sec-ref" v-model="security.reference" class="input" maxlength="80" placeholder="Title or logbook no." />
    </div>
    <div>
      <label for="sec-kept" class="label">Where it is kept <span class="font-normal text-gray-500">(optional)</span></label>
      <input id="sec-kept" v-model="security.kept_at" class="input" maxlength="120" placeholder="e.g. SACCO safe" />
    </div>
  </div>
</template>
