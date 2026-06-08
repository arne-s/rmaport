@props(['page', 'field' => 'faq', 'newsArticle'])

@php
    if (isset($newsArticle)) {
        $faqContent = $newsArticle->faq_content;
        $title = $newsArticle->faq_title;
        $subtitle = $newsArticle->faq_subtitle;
    } else {
        $content = $page->content[$field];
        $faqContent = $content['content'];
        $title = $content['title'];
        $subtitle = $content['subtitle'];
    }
@endphp
@isset ($faqContent)
    <section class="faqSectionContainer pageWidth">
        <div class="faqSectionInner">
            <div class="content">
                <h3>{{ $title }}</h3>

                {!! $subtitle !!}
            </div>

            <div class="faqAccordionContainer">
                @foreach ($faqContent as $faqItem)
                    <div class="faqAccordionItem">
                        <span class="question">{{ $faqItem['question'] }}</span>
                        <span class="answer">{!! $faqItem['answer'] !!}</span>
                    </div>
                @endforeach
            </div>

            <script>
                // Selecteer alle FAQ-items
                const faqItems = document.querySelectorAll('.faqAccordionItem');

                faqItems.forEach(item => {
                    const question = item.querySelector('.question');
                    const answer = item.querySelector('.answer');

                    question.addEventListener('click', () => {
                        const isActive = item.classList.contains('active');

                        // Sluit alle actieve items
                        faqItems.forEach(i => {
                            i.classList.remove('active');
                            i.querySelector('.answer').style.maxHeight = null;
                        });

                        // Als het huidige item niet actief is, open het
                        if (!isActive) {
                            item.classList.add('active');
                            answer.style.maxHeight = answer.scrollHeight + 'px'; // Dynamische hoogte
                        }
                    });
                });
            </script>
        </div>
    </section>
@endisset