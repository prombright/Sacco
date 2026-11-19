<script setup lang="ts">

/**
 * The top of a page, the same in every module: the page's title, a line saying what it is for, and
 * its main buttons on the right.
 *
 *   <PageBanner title="Loans">What the page is for<template #actions>…</template></PageBanner>
 *
 * Slots: #above (a trail or small label over the title), #icon (a tile before the title),
 * #title, default (the line under the title), #below (badges and the like), #actions.
 */
// On phones the line under the title is hidden to keep the screen clear, unless `keepNote` (it holds data)
defineProps<{ title?: string; keepNote?: boolean }>()
</script>

<template>
  <section class="page-banner relative">
    <div class="relative flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
      <div class="flex min-w-0 max-w-3xl items-center gap-4">
        <slot name="icon" />
        <div class="min-w-0">
          <slot name="above" />
          <h1 class="mt-0.5 text-[1.5rem] font-extrabold leading-tight tracking-[-0.015em] text-[#16133a] dark:text-white sm:text-[1.95rem]"><slot name="title">{{ title }}</slot></h1>
          <div v-if="$slots.default" :class="{ 'phone-hide': !keepNote }" class="mt-1 text-[13px] text-gray-600 dark:text-gray-400 sm:text-[0.95rem]"><slot /></div>
          <slot name="below" />
        </div>
      </div>
      <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2 max-sm:w-full"><slot name="actions" /></div>
    </div>
  </section>
</template>
