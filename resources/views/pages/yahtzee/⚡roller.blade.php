<?php

use Livewire\Component;

new class extends Component
{
    //
};
?>

<x-slot:title>Yacht (Dice Only) — Low Stim Play</x-slot>
<x-slot:description>Just the Yacht dice — roll and hold without tracking a scorecard.</x-slot>
<x-slot:fullscreenToggle>1</x-slot:fullscreenToggle>
<x-slot:rulesTitle>Yacht (Dice Only) Rules</x-slot:rulesTitle>
<x-slot:rules>
    <h3>How to Use This Page</h3>
    <ul>
        <li>Press Roll to roll all five dice.</li>
        <li>Tap a die to hold it &mdash; held dice won't reroll.</li>
        <li>Press Reset to clear all dice and start over.</li>
        <li>There's no scorecard here &mdash; pair it with a paper scorecard, the full Yacht page, or however else you're keeping score.</li>
    </ul>

    <h3>Full Rules</h3>
    <ul>
        <li>Each turn, roll the five dice up to three times total, keeping any dice you like between rolls — you don't have to use all three.</li>
        <li>After rolling at least once, choose one open category to score for that turn.</li>
        <li>Ones through Sixes: scores the sum of just the dice matching that number (e.g. three 4s in the Fours box scores 12).</li>
        <li>If your six Upper Section boxes (Ones&ndash;Sixes) add up to 63 or more, you get a 35-point bonus.</li>
        <li>3 of a Kind / 4 of a Kind: if at least three (or four) dice match, scores the sum of all five dice; otherwise 0.</li>
        <li>Full House: scores 25 for three of one number and two of another.</li>
        <li>Small Straight: scores 30 for four sequential numbers (e.g. 2-3-4-5).</li>
        <li>Large Straight: scores 40 for five sequential numbers (1-5 or 2-6).</li>
        <li>Yacht: scores 50 for five of a kind.</li>
        <li>Chance: always scores the sum of all five dice, whatever you rolled.</li>
        <li>Yacht Bonus: once your Yacht box shows 50, every additional five-of-a-kind you roll earns a 100-point bonus chip, on top of whatever category you score that turn.</li>
        <li>Joker rule: once your Yacht box has been filled at all (scored 50 or forced to 0), a later five-of-a-kind lets Full House / Small Straight / Large Straight score their fixed value (25 / 30 / 40) automatically &mdash; but only if the Upper Section box matching the number you rolled is already filled. If it's still open, those three categories score 0 for that roll instead, so scoring the matching Upper Section box (or Chance) is usually the better move.</li>
        <li>The game ends once every player has filled every category; whoever has the highest total wins (ties are possible).</li>
    </ul>
</x-slot:rules>

<div class="yahtzee-roller flex flex-col items-center" style="gap: inherit;"
    x-data="{
        dice: Array.from({ length: 5 }, () => ({ value: null, kept: false })),
        rollNum: 0,
        roll() {
            if (!this.dice.some(d => !d.kept)) return;
            this.dice.forEach(d => { if (!d.kept) d.value = 1 + Math.floor(Math.random() * 6); });
            this.rollNum++;
        },
        toggleKeep(i) {
            if (this.dice[i].value === null) return;
            this.dice[i].kept = !this.dice[i].kept;
        },
        reset() {
            this.dice.forEach(d => { d.value = null; d.kept = false; });
            this.rollNum = 0;
        },
    }">
    <div class="heading">Yacht (Dice Only)</div>

    <div class="status roll-status" x-text="rollNum === 0 ? 'Press Roll to Start' : 'Roll ' + rollNum"></div>

    <div class="row">
        <template x-for="(d, i) in dice" :key="i">
            <div class="die" :class="{ kept: d.kept }" @click="toggleKeep(i)">
                <span class="number" x-text="d.value ?? ''"></span>
            </div>
        </template>
    </div>

    <div class="controls">
        <button type="button" @click="roll">Roll</button>
        <button type="button" @click="reset">Reset</button>
    </div>
</div>
