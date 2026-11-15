/**
 * TBT Flashcards — lesson glossary ([tbt_flashcards_glossary]).
 *
 * Renders search, sort and paging from the rows the server embeds in
 * data-rows. Card text is untrusted: it is only ever set with textContent
 * (highlights are built as <mark> nodes), never as HTML. Each glossary on a
 * page keeps its own state.
 */
( function () {
    'use strict';

    var PER = 10;

    // Fold one character for matching: lower case, accents removed, ł → l.
    // Folding character by character keeps indices aligned with the original
    // text, so highlights land on the right letters.
    function foldChar( ch ) {
        var f = ch.toLowerCase();
        if ( f.normalize ) {
            f = f.normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' );
        }
        if ( 'ł' === f ) {
            f = 'l';
        }
        return f;
    }

    // Folded text plus, for each folded position, the index in the original.
    function foldMap( text ) {
        var out = '';
        var map = [];
        var chars = Array.from ? Array.from( text ) : text.split( '' );
        var pos = 0;
        for ( var i = 0; i < chars.length; i++ ) {
            var f = foldChar( chars[ i ] );
            for ( var j = 0; j < f.length; j++ ) {
                out += f.charAt( j );
                map.push( pos );
            }
            pos += chars[ i ].length;
        }
        map.push( pos );
        return { text: out, map: map };
    }

    function fold( text ) {
        return foldMap( text ).text;
    }

    function format( pattern ) {
        var args = Array.prototype.slice.call( arguments, 1 );
        var n = 0;
        return String( pattern ).replace( /%(?:(\d+)\$)?([ds])/g, function ( m, idx ) {
            var v = idx ? args[ parseInt( idx, 10 ) - 1 ] : args[ n++ ];
            return undefined === v ? '' : String( v );
        } );
    }

    function parse( json, fallback ) {
        try {
            var v = JSON.parse( json || '' );
            return v || fallback;
        } catch ( e ) {
            return fallback;
        }
    }

    function init( root ) {
        if ( root.getAttribute( 'data-ready' ) ) {
            return;
        }
        root.setAttribute( 'data-ready', '1' );

        var data = parse( root.getAttribute( 'data-rows' ), [] ).filter( function ( r ) {
            return Array.isArray( r ) && 'string' === typeof r[ 0 ];
        } ).map( function ( r ) {
            return [ r[ 0 ], 'string' === typeof r[ 1 ] ? r[ 1 ] : '' ];
        } );
        var t = parse( root.getAttribute( 'data-i18n' ), {} );
        var folded = data.map( function ( r ) {
            return [ fold( r[ 0 ] ), fold( r[ 1 ] ) ];
        } );

        var q = function ( sel ) { return root.querySelector( sel ); };
        var input = q( '.tbt-fc-gl-input' );
        var clearBtn = q( '.tbt-fc-gl-clear' );
        var count = q( '.tbt-fc-gl-count' );
        var tbody = q( '.tbt-fc-gl-rows' );
        var pager = q( '.tbt-fc-gl-pager' );
        var prev = q( '.tbt-fc-gl-prev' );
        var next = q( '.tbt-fc-gl-next' );
        var range = q( '.tbt-fc-gl-range' );
        var allBtn = q( '.tbt-fc-gl-all' );
        var heads = root.querySelectorAll( 'th[data-col]' );

        var page = 0;
        var showAll = false;
        var sortCol = null;
        var sortDir = 1;
        var query = '';

        function needle() {
            return fold( query.trim() );
        }

        function currentRows() {
            var n = needle();
            var rows = [];
            for ( var i = 0; i < data.length; i++ ) {
                if ( ! n || folded[ i ][ 0 ].indexOf( n ) > -1 || folded[ i ][ 1 ].indexOf( n ) > -1 ) {
                    rows.push( i );
                }
            }
            if ( null !== sortCol ) {
                var locale = sortCol ? 'pl' : 'en';
                rows.sort( function ( a, b ) {
                    return sortDir * data[ a ][ sortCol ].localeCompare( data[ b ][ sortCol ], locale, { sensitivity: 'base' } ) || a - b;
                } );
            }
            return rows;
        }

        function highlight( cell, text, n ) {
            if ( ! n ) {
                cell.textContent = text;
                return;
            }
            var fm = foldMap( text );
            var from = 0;
            var i = 0;
            var at;
            while ( ( at = fm.text.indexOf( n, i ) ) > -1 ) {
                var start = fm.map[ at ];
                var end = fm.map[ at + n.length ];
                if ( start > from ) {
                    cell.appendChild( document.createTextNode( text.slice( from, start ) ) );
                }
                var mark = document.createElement( 'mark' );
                mark.className = 'tbt-fc-gl-mark';
                mark.textContent = text.slice( start, end );
                cell.appendChild( mark );
                from = end;
                i = at + n.length;
            }
            if ( from < text.length ) {
                cell.appendChild( document.createTextNode( text.slice( from ) ) );
            }
        }

        function render() {
            var rows = currentRows();
            var total = rows.length;
            var pages = Math.max( 1, Math.ceil( total / PER ) );
            var n = needle();
            if ( page >= pages ) {
                page = pages - 1;
            }
            var shown = showAll ? rows : rows.slice( page * PER, page * PER + PER );

            tbody.textContent = '';
            if ( ! total ) {
                var tr = document.createElement( 'tr' );
                tr.className = 'tbt-fc-gl-empty';
                var td = document.createElement( 'td' );
                td.colSpan = 2;
                td.appendChild( document.createTextNode( format( t.noMatch || 'No expressions match “%s”.', query.trim() ) + ' ' ) );
                var b = document.createElement( 'button' );
                b.type = 'button';
                b.className = 'tbt-fc-gl-linkbtn';
                b.textContent = t.clear || 'Clear search';
                b.addEventListener( 'click', clearQuery );
                td.appendChild( b );
                tr.appendChild( td );
                tbody.appendChild( tr );
            }
            shown.forEach( function ( idx ) {
                var row = document.createElement( 'tr' );
                var a = document.createElement( 'td' );
                var c = document.createElement( 'td' );
                highlight( a, data[ idx ][ 0 ], n );
                highlight( c, data[ idx ][ 1 ], n );
                row.appendChild( a );
                row.appendChild( c );
                tbody.appendChild( row );
            } );

            count.textContent = query.trim()
                ? format( t.countOf || '%1$d of %2$d expressions', total, data.length )
                : format( t.count || '%d expressions', data.length );

            var from = total ? ( showAll ? 1 : page * PER + 1 ) : 0;
            var to = showAll ? total : Math.min( total, page * PER + PER );
            var paged = total > PER;
            pager.hidden = ! paged;
            prev.hidden = next.hidden = range.hidden = showAll || ! paged;
            range.textContent = total ? format( t.range || '%1$d–%2$d of %3$d', from, to, total ) : '';
            prev.disabled = 0 === page;
            next.disabled = page >= pages - 1;
            var left = total - to;
            next.textContent = format( t.next || 'Next %d', left > 0 && left < PER ? left : PER );
            allBtn.hidden = ! paged;
            allBtn.textContent = showAll ? ( t.showPaged || 'Show 10 at a time' ) : format( t.showAll || 'Show all %d', total );

            Array.prototype.forEach.call( heads, function ( th ) {
                var col = parseInt( th.getAttribute( 'data-col' ), 10 );
                var on = col === sortCol;
                th.setAttribute( 'aria-sort', on ? ( sortDir > 0 ? 'ascending' : 'descending' ) : 'none' );
                var arrow = th.querySelector( '.tbt-fc-gl-arrow' );
                if ( arrow ) {
                    arrow.textContent = on ? ( sortDir > 0 ? '▲' : '▼' ) : '↕';
                }
            } );
        }

        function clearQuery() {
            query = '';
            input.value = '';
            clearBtn.hidden = true;
            page = 0;
            render();
            input.focus();
        }

        input.addEventListener( 'input', function () {
            query = input.value;
            clearBtn.hidden = ! query;
            page = 0;
            render();
        } );
        input.addEventListener( 'keydown', function ( e ) {
            if ( 'Escape' === e.key || 'Esc' === e.key ) {
                e.preventDefault();
                clearQuery();
            }
        } );
        clearBtn.addEventListener( 'click', clearQuery );
        prev.addEventListener( 'click', function () {
            if ( page > 0 ) {
                page--;
                render();
            }
        } );
        next.addEventListener( 'click', function () {
            page++;
            render();
        } );
        allBtn.addEventListener( 'click', function () {
            showAll = ! showAll;
            page = 0;
            render();
        } );
        Array.prototype.forEach.call( heads, function ( th ) {
            var btn = th.querySelector( 'button' );
            if ( ! btn ) {
                return;
            }
            btn.addEventListener( 'click', function () {
                var col = parseInt( th.getAttribute( 'data-col' ), 10 );
                if ( sortCol === col ) {
                    if ( sortDir > 0 ) {
                        sortDir = -1;
                    } else {
                        sortCol = null;
                        sortDir = 1;
                    }
                } else {
                    sortCol = col;
                    sortDir = 1;
                }
                page = 0;
                render();
            } );
        } );

        render();
    }

    function boot() {
        Array.prototype.forEach.call( document.querySelectorAll( '.tbt-fc-gl[data-rows]' ), init );
    }

    if ( 'loading' === document.readyState ) {
        document.addEventListener( 'DOMContentLoaded', boot );
    } else {
        boot();
    }
}() );
